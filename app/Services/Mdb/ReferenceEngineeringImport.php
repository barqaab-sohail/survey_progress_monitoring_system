<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Imports observations, never an engineering approval or another project's network. */
class ReferenceEngineeringImport
{
    public const TABLES = ['SAI_Control', 'Node', 'InstFeeders', 'InstSection', 'InstPrimaryTransformers', 'Loads', 'InstMeterPoints', 'InstDGens', 'InstMymDGens'];

    private const INSTANCE_FIELDS = [
        'Node' => ['NodeId', 'X', 'Y', 'Description'],
        'InstFeeders' => ['FeederId', 'SubstationId', 'Note_', 'LocationLink', 'LastSavedDate', 'LastSavedBy', 'BuiltDate'],
        'InstSection' => ['SectionId', 'FeederId', 'FromNodeId', 'ToNodeId', 'SectionLength_MUL', 'SectionPhases', 'Description', 'PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id', 'NeutralConductorId', 'Note_', 'LocationLink', 'AMSLink'],
        'InstPrimaryTransformers' => ['SectionId', 'UniqueDeviceId', 'TransformerType', 'Note_', 'LocationLink', 'AMSLink'],
        'Loads' => ['SectionId', 'Description'],
        'InstMeterPoints' => ['SectionId', 'Description'],
        'InstDGens' => ['SectionId', 'DGenDesc'],
        'InstMymDGens' => ['SectionId', 'YearNumber'],
    ];

    public function analyze(array $documents, int $expectedCount = 10): array
    {
        if ($expectedCount < 2 || count($documents) !== $expectedCount) {
            throw ValidationException::withMessages(['references' => 'Exactly '.$expectedCount.' independently inspected MDB references are required.']);
        }
        $sources = [];
        $schema = null;
        $byTable = [];
        foreach ($documents as $document) {
            if (! preg_match('/^[a-f0-9]{64}$/i', $document['sha256'] ?? '') || isset($sources[$document['file'] ?? ''])) {
                throw ValidationException::withMessages(['references' => 'Each reference needs a unique filename and SHA-256.']);
            }
            $currentSchema = [];
            foreach ($document['tables'] as $table) {
                $currentSchema[$table['name']] = ['fields' => $table['fields'], 'indexes' => $table['indexes']];
                $byTable[$table['name']][$document['file']] = $table['rows'];
            }
            ksort($currentSchema);
            $fingerprint = hash('sha256', json_encode([$currentSchema, $document['relationships']], JSON_THROW_ON_ERROR));
            $schema ??= $fingerprint;
            if ($schema !== $fingerprint) {
                throw ValidationException::withMessages(['references' => 'Reference schemas differ; resolve the schema conflict before importing.']);
            }
            $sources[$document['file']] = ['file' => $document['file'], 'sha256' => strtolower($document['sha256']),
                'table_count' => count($document['tables']), 'relationship_count' => count($document['relationships'])];
        }
        $tableValues = [];
        $evidence = [];
        $conflicts = [];
        $missing = [];
        $constantCount = 0;
        $conditionalValues = [];
        foreach (self::TABLES as $table) {
            $tableValues[$table] = [];
            $rowsByFile = $byTable[$table] ?? [];
            $fields = [];
            foreach ($rowsByFile as $rows) {
                foreach ($rows as $row) {
                    $fields += array_fill_keys(array_keys($row), true);
                }
            }
            foreach (array_keys($fields) as $field) {
                $values = [];
                $populatedFiles = [];
                foreach ($rowsByFile as $file => $rows) {
                    foreach ($rows as $row) {
                        $value = $row[$field] ?? null;
                        $key = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                        $values[$key] ??= ['value' => $value, 'files' => [], 'rows' => 0];
                        $values[$key]['files'][$file] = true;
                        $values[$key]['rows']++;
                        $populatedFiles[$file] = true;
                    }
                }
                $variants = array_map(fn ($value) => ['value' => $value['value'], 'files' => array_keys($value['files']), 'rows' => $value['rows']], array_values($values));
                $path = $table.'.'.$field;
                $instance = in_array($field, self::INSTANCE_FIELDS[$table] ?? [], true)
                    || ($table === 'SAI_Control' && str_starts_with($field, 'MYM_YearDesc'))
                    || ($table === 'Loads' && preg_match('/^Phase[123](Customers|Kw|Kvar|Kva|Kwh)$/', $field))
                    || ($table === 'InstMymDGens' && preg_match('/^Phase[123](Kw|Kvar)$/', $field));
                $evidence[$path] = ['files_with_rows' => count($populatedFiles), 'variants' => $variants, 'instance_specific' => (bool) $instance];
                if ($instance) {
                    continue;
                }
                if (count($variants) === 1 && $variants[0]['value'] !== null && $variants[0]['value'] !== '' && count($populatedFiles) === count($documents)) {
                    $tableValues[$table][$field] = $variants[0]['value'];
                    $constantCount++;
                } elseif (count($variants) === 1 && $variants[0]['value'] !== null && $variants[0]['value'] !== ''
                    && in_array($table, ['InstDGens', 'InstMymDGens'], true)) {
                    $conditionalValues[$table][$field] = $variants[0]['value'];
                    $tableValues[$table][$field] = $variants[0]['value'];
                } elseif (count($variants) > 1) {
                    $conflicts[] = ['field' => $path, 'values' => $variants];
                } elseif ($variants !== []) {
                    $missing[] = ['field' => $path, 'reason' => $variants[0]['value'] === null || $variants[0]['value'] === ''
                        ? 'Blank in all populated reference rows.' : 'Not evidenced in all '.$expectedCount.' reference files.'];
                }
            }
        }
        $references = ['conductors' => [], 'transformers' => [], 'configurations' => []];
        foreach ($byTable['InstSection'] ?? [] as $file => $rows) {
            foreach ($rows as $row) {
                foreach (['PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id', 'NeutralConductorId'] as $field) {
                    if (is_string($row[$field] ?? null) && trim($row[$field]) !== '') {
                        $references['conductors'][$row[$field]] = true;
                    }
                }
                if (! empty($row['ConfigurationId'])) {
                    $references['configurations'][$row['ConfigurationId']] = true;
                }
            }
        }
        foreach ($byTable['InstPrimaryTransformers'] ?? [] as $rows) {
            foreach ($rows as $row) {
                if (! empty($row['TransformerType'])) {
                    $references['transformers'][$row['TransformerType']] = true;
                }
            }
        }
        foreach ($references as &$list) {
            $list = array_keys($list);
            sort($list, SORT_STRING);
        }
        unset($list);
        $roleRows = ['transformer_supply' => [], 'transformer_secondary_link' => []];
        foreach ($byTable['InstPrimaryTransformers'] ?? [] as $file => $transformers) {
            $sections = array_column($byTable['InstSection'][$file] ?? [], null, 'SectionId');
            foreach ($transformers as $transformer) {
                $supply = $sections[$transformer['SectionId']] ?? null;
                if (! $supply) {
                    continue;
                }
                $roleRows['transformer_supply'][$file] = $supply;
                $downstream = array_values(array_filter($sections, fn ($section) => $section['FromNodeId'] === $supply['ToNodeId']));
                if (count($downstream) === 1) {
                    $roleRows['transformer_secondary_link'][$file] = $downstream[0];
                }
            }
        }
        $roleValues = [];
        foreach ($roleRows as $role => $rows) {
            if (count($rows) !== count($documents)) {
                continue;
            }
            foreach (['SectionPhases', 'PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id', 'NeutralConductorId', 'LoadConnectionPhaseGnd', 'SectionLength_MUL'] as $field) {
                $values = array_values(array_unique(array_map(fn ($row) => json_encode($row[$field], JSON_THROW_ON_ERROR), $rows)));
                if (count($values) === 1) {
                    $roleValues[$role][$field] = json_decode($values[0], true, 64, JSON_THROW_ON_ERROR);
                }
            }
        }
        $ratios = [];
        $ratioCount = $threeCount = $inconsistentPower = 0;
        foreach ($byTable['Loads'] ?? [] as $file => $rows) {
            foreach ($rows as $row) {
                foreach ([1, 2, 3] as $phase) {
                    $customers = $row['Phase'.$phase.'Customers'] ?? null;
                    $kva = $row['Phase'.$phase.'Kva'] ?? null;
                    if (is_numeric($customers) && $customers > 0 && is_numeric($kva)) {
                        $ratio = $kva / $customers;
                        $key = json_encode($ratio, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                        $ratios[$key] ??= ['value' => $ratio, 'files' => [], 'rows' => 0];
                        $ratios[$key]['files'][$file] = true;
                        $ratios[$key]['rows']++;
                        $ratioCount++;
                        if (abs($ratio - 3) < 1e-9) {
                            $threeCount++;
                        }
                    }
                    if (is_numeric($kva) && $kva > 0 && (float) ($row['Phase'.$phase.'Kw'] ?? 0) === 0.0 && (float) ($row['Phase'.$phase.'Kvar'] ?? 0) === 0.0) {
                        $inconsistentPower++;
                    }
                }
            }
        }
        $ratioEvidence = array_map(fn ($variant) => ['value' => $variant['value'], 'files' => array_keys($variant['files']), 'rows' => $variant['rows']], array_values($ratios));
        $observedRatios = array_column($ratioEvidence, 'value');
        if (count($ratioEvidence) > 1 && max($observedRatios) - min($observedRatios) > 1e-9) {
            $conflicts[] = ['field' => 'Loads.observed_kva_per_customer', 'values' => $ratioEvidence];
        }
        if ($inconsistentPower > 0) {
            $conflicts[] = ['field' => 'Loads.power_factor_inputs', 'values' => [['value' => 'Positive kVA with zero kW and kvar in '.$inconsistentPower.' phase records; no verified power factor can be derived.', 'files' => array_keys($byTable['Loads'])]]];
        }
        $groups = [];
        foreach ($references['conductors'] as $reference) {
            $groups[trim($reference)][] = $reference;
        }
        foreach ($groups as $family => $variants) {
            if (count($variants) > 1) {
                $conflicts[] = ['field' => 'equipment_references.conductors.'.$family,
                    'values' => array_map(fn ($value) => ['value' => $value, 'files' => array_values(array_filter(array_keys($byTable['InstSection']), function ($file) use ($byTable, $value) {
                        foreach ($byTable['InstSection'][$file] as $row) {
                            foreach (['PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id', 'NeutralConductorId'] as $field) {
                                if (($row[$field] ?? null) === $value) {
                                    return true;
                                }
                            }
                        }

                        return false;
                    }))], $variants)];
            }
        }
        if (str_contains($roleValues['transformer_secondary_link']['SectionPhases'] ?? '', 'N') && trim($roleValues['transformer_secondary_link']['NeutralConductorId'] ?? '') === '') {
            $conflicts[] = ['field' => 'transformer_secondary_link.neutral', 'values' => [['value' => 'N is present in section phases but the neutral conductor reference is blank.', 'files' => array_keys($roleRows['transformer_secondary_link'])]]];
        }
        $control = $tableValues['SAI_Control'];
        $engineering = ['version' => 1, 'crs_name' => $control['ProjectionFile'] ?? null, 'projection_wkt' => null,
            'configuration_id' => $tableValues['InstSection']['ConfigurationId'] ?? null,
            'phase_spacing_cm' => $tableValues['InstSection']['PhaseToPhaseSpacing_MUL'] ?? null,
            'neutral_spacing_cm' => $tableValues['InstSection']['PhaseToNeutralSpacing_MUL'] ?? null,
            'conductor_height_m' => $tableValues['InstSection']['AveHeightAboveGround_MUL'] ?? null,
            'supply_conductor_id' => null, 'secondary_conductor_id' => null, 'blank_consumers_zero' => null, 'transformer_types' => []];
        $epsg = null;
        if (preg_match('/^WGS 1984 UTM Zone ([1-9]|[1-5][0-9]|60)([NS])$/D', $control['ProjectionFile'] ?? '', $match)) {
            $epsg = ($match[2] === 'N' ? 32600 : 32700) + (int) $match[1];
        }
        $notes = [
            'Files are user-supplied reference models. Imported observations do not constitute engineering approval for HAZECO.',
            'EPSG, when populated, is translated from the recorded WGS84 UTM label only. Coordinates have not independently verified that label.',
            'Frequency is copied exactly from the references. Do not silently replace the recorded value with a locally expected frequency.',
            'Spacing and height numbers are copied without conversion into the existing application mapping; the source only explicitly declares Metric length units.',
            'SpecNomKv is retained with NomKvMode in advanced mapping; it is not used to guess an LT secondary voltage.',
            'UseInstanceImpedance=0 means the numeric zero impedance fields do not establish physical zero impedance.',
            'Referenced equipment IDs are retained literally, including whitespace. They do not include installed library revisions or electrical library definitions.',
            'No previous-project node IDs, coordinates, feeder/substation names, transformer IDs, historic model years or source wiring are installed as HAZECO defaults.',
            'Repeated kVA/customer ratios are observations, not verified consumer-category rates or a power-factor/phase-allocation policy.',
        ];
        foreach ([
            'secondary_voltage_kv' => 'Actual LT secondary voltage is not established by the references; SpecNomKv=4.16 with NomKvMode=C is preserved separately.',
            'template_id' => 'No clean HAZECO-approved template is established by populated reference databases.',
            'conductor_library_revision' => 'Installed conductor library version and its electrical definitions are not present.',
            'transformer_library_revision' => 'Installed transformer library version and winding/impedance definitions are not present.',
            'transformer_types' => 'The reference type label alone does not independently verify a capacity-to-library mapping for HAZECO.',
            'supply_conductor_id' => 'A single ID for all supply phases cannot resolve whitespace-distinct library references without an approved alias policy.',
            'secondary_conductor_id' => 'Secondary-link neutral is marked present in phases but its conductor ID is blank.',
            'load_mode' => 'Consumer categories and an approved category demand/power-factor method are absent.',
            'mapping.networks' => 'Historical network wiring belongs to different transformers and must not be applied to HAZECO surveys.',
        ] as $field => $reason) {
            $missing[] = compact('field', 'reason');
        }

        return ['epsg' => $epsg, 'settings' => ['frequency_hz' => $control['Frequency'] ?? null,
            'nominal_voltage_kv' => $tableValues['InstFeeders']['NominalKvll'] ?? null, 'secondary_voltage_kv' => null,
            'conductor_library_revision' => null, 'transformer_library_revision' => null,
            'entry' => ['pole_height_unit' => 'ft', 'two_digit_year_start' => 2000], 'engineering' => array_replace($engineering, ['blank_consumers_zero' => true]),
            'mapping' => ['version' => ExportPayloadBuilder::EXTENDED_VERSION, 'table_values' => $tableValues, 'networks' => []],
            'reference_import' => ['status' => 'needs_review', 'sources' => array_values($sources), 'schema_sha256' => $schema,
                'consistent_parameter_count' => $constantCount, 'conditional_parameter_count' => array_sum(array_map('count', $conditionalValues)),
                'conditional_table_values' => $conditionalValues, 'role_table_values' => $roleValues,
                'load_evidence' => ['nonzero_customer_phase_records' => $ratioCount, 'three_kva_per_customer_records' => $threeCount,
                    'other_ratio_records' => $ratioCount - $threeCount, 'inconsistent_power_records' => $inconsistentPower],
                'conflicts' => $conflicts, 'missing' => $missing,
                'equipment_references' => $references, 'notes' => $notes]],
            'load_assumptions' => ['mode' => null, 'method' => null, 'evidence' => $expectedCount.' reference MDBs; see reference_import source SHA-256 and load evidence report.', 'rates' => EngineeringConfiguration::consumerRates()],
            'field_evidence' => $evidence];
    }

    public function import(Project $project, User $actor, array $analysis): Configuration
    {
        abort_unless($actor->isActive() && $actor->hasRole('super_admin'), 403);

        return DB::transaction(function () use ($project, $actor, $analysis) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $configuration = Configuration::where('project_id', $project->id)->lockForUpdate()->first();
            if ($configuration) {
                throw ValidationException::withMessages(['configuration' => 'An existing configuration must be reviewed before replacing it; this import only populates an empty project configuration.']);
            }
            $configuration = Configuration::create(['project_id' => $project->id, 'revision' => 1,
                'epsg' => $analysis['epsg'], 'settings' => $analysis['settings'], 'load_assumptions' => $analysis['load_assumptions'],
                'approved_by' => null, 'approved_at' => null]);
            app(AuditService::class)->record($actor, 'mdb.engineering_reference_imported', $configuration, [], $configuration->toArray(),
                'User-authorized import from ten previously approved MDB references; conflicts and missing parameters remain unresolved, editable and unapproved.');

            return $configuration;
        });
    }
}
