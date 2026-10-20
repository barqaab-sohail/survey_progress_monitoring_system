<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\EngineeringPreset;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EngineeringDefaults
{
    public const CODE = 'reference-mdb-design-v1';

    public function library(): ?EngineeringPreset
    {
        return EngineeringPreset::where('code', self::CODE)->first();
    }

    /** Preview is read-only: opening an Admin form never changes project approvals. */
    public function preview(Project $project): ?Configuration
    {
        $existing = Configuration::where('project_id', $project->id)->first();
        if ($existing) {
            return $existing;
        }
        $preset = $this->library();

        return $preset?->enabled ? new Configuration($this->attributes($project, $preset, 0)) : null;
    }

    /** Called only when creating a new workspace. Existing configurations are never merged or replaced. */
    public function initialize(Project $project, User $actor): ?Configuration
    {
        return DB::transaction(function () use ($project, $actor) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = Configuration::where('project_id', $project->id)->first();
            if ($existing) {
                return $existing;
            }
            $preset = $this->library();
            if (! $preset?->enabled) {
                return null;
            }
            $configuration = Configuration::create($this->attributes($project, $preset, 1));
            app(AuditService::class)->record($actor, 'mdb.engineering_defaults_loaded', $configuration, [], $configuration->toArray(),
                'Initialized an editable engineering draft from library '.$preset->code.' revision '.$preset->revision.'.');

            return $configuration;
        });
    }

    private function attributes(Project $project, EngineeringPreset $preset, int $revision): array
    {
        $payload = $preset->configuration;
        $settings = $payload['settings'];
        $settings['reference_import'] = $preset->evidence;
        $settings['reference_import']['equipment_references'] = $payload['equipment_references'];
        $settings['reference_import']['status'] = 'needs_review';
        // Per-field variants remain in the library, rather than duplicating a large evidence document per project.
        unset($settings['reference_import']['field_evidence']);
        $settings['defaults_library'] = ['code' => $preset->code, 'revision' => $preset->revision];

        return ['project_id' => $project->id, 'epsg' => $payload['epsg'], 'settings' => $settings,
            'load_assumptions' => $payload['load_assumptions'], 'revision' => $revision, 'approved_by' => null, 'approved_at' => null];
    }

    public function validate(string $json): array
    {
        $object = json_decode($json);
        if (! $object instanceof \stdClass) {
            throw ValidationException::withMessages(['configuration_json' => 'Enter a JSON object containing epsg, settings, load_assumptions and equipment_references.']);
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $rules = [
            'epsg' => 'present|nullable|integer|between:1000,999999',
            'settings' => 'required|array:frequency_hz,nominal_voltage_kv,secondary_voltage_kv,template_id,conductor_library_revision,transformer_library_revision,entry,engineering,mapping',
            'settings.frequency_hz' => ['nullable', Rule::in([50, 60])],
            'settings.nominal_voltage_kv' => 'nullable|numeric|gt:0|max:1000',
            'settings.secondary_voltage_kv' => 'nullable|numeric|gt:0|max:1000',
            'settings.template_id' => ['nullable', 'integer', Rule::exists('mdb_workflow_templates', 'id')->where(fn ($q) => $q->where('active', true)->whereNotNull('approved_by')->whereNotNull('approved_at'))],
            'settings.conductor_library_revision' => 'nullable|string|max:200',
            'settings.transformer_library_revision' => 'nullable|string|max:200',
            'settings.entry' => 'present|array:pole_height_unit,two_digit_year_start',
            'settings.entry.pole_height_unit' => 'nullable|in:ft,m',
            'settings.entry.two_digit_year_start' => 'nullable|integer|between:1800,9900',
            'settings.engineering' => 'required|array:version,crs_name,projection_wkt,configuration_id,phase_spacing_cm,neutral_spacing_cm,conductor_height_m,supply_conductor_id,secondary_conductor_id,blank_consumers_zero,transformer_types',
            'settings.engineering.version' => 'required|in:1',
            'settings.engineering.crs_name' => 'nullable|string|max:255',
            'settings.engineering.projection_wkt' => 'nullable|string|max:255',
            'settings.engineering.blank_consumers_zero' => 'nullable|boolean',
            'settings.engineering.transformer_types' => 'present|array',
            'settings.engineering.transformer_types.*' => 'required|string|max:200',
            'settings.mapping' => 'required|array:version,table_values,networks',
            'settings.mapping.version' => ['required', Rule::in([ExportPayloadBuilder::VERSION, ExportPayloadBuilder::EXTENDED_VERSION])],
            'settings.mapping.table_values' => 'required|array',
            'settings.mapping.networks' => 'present|array|size:0',
            'load_assumptions' => 'present|array:mode,method,evidence,rates',
            'load_assumptions.mode' => 'nullable|in:manual,category_rates',
            'load_assumptions.method' => 'nullable|string|max:4000',
            'load_assumptions.evidence' => 'nullable|string|max:4000',
            'load_assumptions.rates' => 'present|array:rs,rl,sc,lc,si,li,pb,ag,st',
            'load_assumptions.rates.*' => 'array:kva,power_factor',
            'load_assumptions.rates.*.kva' => 'nullable|numeric|min:0|max:1000000',
            'load_assumptions.rates.*.power_factor' => 'nullable|numeric|gt:0|max:1',
            'equipment_references' => 'required|array:conductors,transformers,configurations',
        ];
        foreach (['configuration_id', 'supply_conductor_id', 'secondary_conductor_id'] as $key) {
            $rules['settings.engineering.'.$key] = 'nullable|string|max:200';
        }
        foreach (['phase_spacing_cm', 'neutral_spacing_cm', 'conductor_height_m'] as $key) {
            $rules['settings.engineering.'.$key] = 'nullable|numeric|gt:0|max:'.($key === 'conductor_height_m' ? '200' : '10000');
        }
        foreach (['conductors', 'transformers', 'configurations'] as $key) {
            $rules['equipment_references.'.$key] = 'present|array|list|max:10000';
            $rules['equipment_references.'.$key.'.*'] = 'required|string|max:200';
        }
        if (array_diff(array_keys($data), ['epsg', 'settings', 'load_assumptions', 'equipment_references'])) {
            throw ValidationException::withMessages(['configuration_json' => 'Unknown configuration properties are not supported.']);
        }
        validator($data, $rules)->validate();
        foreach ($data['settings']['engineering']['transformer_types'] as $capacity => $type) {
            if (! is_numeric($capacity) || (float) $capacity <= 0) {
                throw ValidationException::withMessages(['configuration_json' => 'Transformer type mappings need positive numeric kVA capacity keys.']);
            }
        }
        $this->validateMapping($data['settings']['mapping']);
        if ($data['epsg'] !== null) {
            try {
                app(ProjectionService::class)->projectMany([], (int) $data['epsg']);
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['configuration_json' => $exception->getMessage()]);
            }
        }

        return $data;
    }

    private function validateMapping(array $mapping): void
    {
        $schema = json_decode(file_get_contents(resource_path('mdb/approved-schema.json')), true, 512, JSON_THROW_ON_ERROR)['schema'];
        $tables = array_column($schema['tables'], null, 'name');
        $required = $mapping['version'] === ExportPayloadBuilder::EXTENDED_VERSION ? ReferenceEngineeringImport::TABLES : ExportPayloadBuilder::TABLES;
        if (array_diff(array_keys($mapping['table_values']), $required) || array_diff($required, array_keys($mapping['table_values']))) {
            throw ValidationException::withMessages(['configuration_json' => 'Mapping must contain exactly the tables supported by its existing mapping version.']);
        }
        $instance = [
            'Node' => ['NodeId', 'X', 'Y', 'Description'],
            'InstFeeders' => ['FeederId', 'SubstationId', 'Note_', 'LocationLink', 'LastSavedDate', 'LastSavedBy', 'BuiltDate'],
            'InstSection' => ['SectionId', 'FeederId', 'FromNodeId', 'ToNodeId', 'SectionLength_MUL', 'SectionPhases', 'Description', 'PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id', 'NeutralConductorId', 'Note_', 'LocationLink', 'AMSLink'],
            'InstPrimaryTransformers' => ['SectionId', 'UniqueDeviceId', 'TransformerType', 'Note_', 'LocationLink', 'AMSLink'],
            'Loads' => ['SectionId', 'Description'], 'InstMeterPoints' => ['SectionId', 'Description'],
            'InstDGens' => ['SectionId', 'DGenDesc'], 'InstMymDGens' => ['SectionId', 'YearNumber'],
        ];
        foreach ($mapping['table_values'] as $table => $values) {
            if (! is_array($values) || array_is_list($values) && $values !== []) {
                throw ValidationException::withMessages(['configuration_json' => $table.' values must be a field dictionary.']);
            }
            $fields = array_column($tables[$table]['fields'], null, 'name');
            foreach ($values as $name => $value) {
                if (! isset($fields[$name]) || in_array($name, $instance[$table] ?? [], true)
                    || ($table === 'SAI_Control' && str_starts_with($name, 'MYM_YearDesc'))
                    || ($table === 'Loads' && preg_match('/^Phase[123](Customers|Kw|Kvar|Kva|Kwh)$/', $name))
                    || ($table === 'InstMymDGens' && preg_match('/^Phase[123](Kw|Kvar)$/', $name))) {
                    throw ValidationException::withMessages(['configuration_json' => $table.'.'.$name.' is unknown or specific to an individual network; it cannot be a shared default.']);
                }
                $field = $fields[$name];
                $rule = match ($field['type']) {
                    1 => 'nullable|boolean',
                    3 => 'nullable|integer|between:-32768,32767',
                    4 => 'nullable|integer|between:-2147483648,2147483647',
                    7 => 'nullable|numeric',
                    10 => 'nullable|string|max:'.$field['size'],
                    12 => 'nullable|string',
                    default => 'prohibited',
                };
                validator([$name => $value], [$name => $rule])->validate();
            }
        }
    }
}
