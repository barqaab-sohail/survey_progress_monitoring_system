<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use Illuminate\Validation\ValidationException;

/** Pure mapping from a frozen revision. Sample defaults and live survey rows are never read. */
class ExportPayloadBuilder
{
    public const VERSION = 'synergee-reviewed-v1';

    public const TABLES = ['SAI_Control', 'Node', 'InstFeeders', 'InstSection', 'InstPrimaryTransformers', 'Loads'];

    public function __construct(private readonly ProjectionService $projection) {}

    public function build(Revision $revision, Template $template, ?int $transformerId, string $idempotencyKey): array
    {
        $snapshot = $revision->snapshot;
        $configuration = $snapshot['configuration'] ?? [];
        $settings = $configuration['settings'] ?? [];
        $mapping = $settings['mapping'] ?? [];
        $this->require(! empty($configuration['approved_by']) && ! empty($configuration['approved_at']), 'Project configuration needs engineering approval.');
        $this->require(($mapping['version'] ?? '') === self::VERSION && ! empty($mapping['reviewed_by']) && ! empty($mapping['reviewed_at']), 'A reviewed synergee-reviewed-v1 mapping is required.');
        $this->require((float) ($settings['frequency_hz'] ?? 0) > 0 && (float) ($settings['nominal_voltage_kv'] ?? 0) > 0, 'Approved frequency and nominal voltage are required.');
        $epsg = (int) ($configuration['epsg'] ?? 0);
        $this->require($epsg > 0, 'An approved projected CRS is required.');
        $this->require($template->approved_by && $template->approved_at && $template->active, 'A clean approved active MDB template is required.');
        $frozenTemplate = $snapshot['template'] ?? [];
        $this->require(($frozenTemplate['sha256'] ?? '') === $template->sha256 && ($frozenTemplate['version'] ?? '') === $template->version, 'Template differs from the approved immutable revision.');
        $transformers = array_values(array_filter($snapshot['transformers'] ?? [], fn ($t) => $transformerId === null || (int) $t['id'] === $transformerId));
        $this->require($transformers !== [], 'The approved revision contains no selected transformer.');
        $this->require(count($transformers) === 1 || ($mapping['consolidated_supported'] ?? false) === true, 'This approved mapping does not permit a consolidated feeder export.');
        $base = $mapping['table_values'] ?? [];
        foreach (self::TABLES as $table) {
            $this->require(isset($base[$table]) && is_array($base[$table]), 'Explicit reviewed table values are required for '.$table.'.');
        }
        $control = $base['SAI_Control'];
        $this->require(($control['LengthUnits'] ?? '') === 'Metric' && (float) ($control['Frequency'] ?? 0) === (float) $settings['frequency_hz'], 'SAI_Control must use approved frequency and metric length units.');
        $this->require(($control['Product'] ?? '') === $template->synergee_version && ! empty($control['ProjectionFile']), 'SAI_Control needs matching SynerGEE product and projection metadata.');
        $tables = array_fill_keys(self::TABLES, []);
        $tables['SAI_Control'][] = $control;
        $waypoints = [];
        foreach ($snapshot['waypoints'] ?? [] as $point) {
            $waypoints[(int) $point['id']] = $point;
        }
        $nodes = $feeders = [];
        $provenance = [];
        $rawWaypoints = $waypoints;
        foreach ($transformers as $transformer) {
            $identityNetwork = app(SurveyWaypoint::class)->network($transformer, $rawWaypoints);
            $waypoints = $identityNetwork['points'];
            $transformer['sections'] = $identityNetwork['sections'];
            $identityAliases = $identityNetwork['aliases'];
            foreach ($transformer['pv_records'] ?? [] as $pv) {
                if ((float) ($pv['installed_capacity_kw'] ?? 0) > 0) {
                    $exclusion = $mapping['pv_exclusion'] ?? [];
                    $this->require(! empty($exclusion['reason']) && ! empty($exclusion['reviewed_at']) && (int) ($exclusion['reviewed_by'] ?? 0) === (int) $configuration['approved_by'], 'Positive PV capacity needs a reviewed generator mapping or a documented exclusion approved by the configuration verifier.');
                }
            }
            $network = $mapping['networks'][$transformer['code']] ?? [];
            $this->require(! empty($network['source_nodes']) && ! empty($network['source_sections']), 'Approved source and transformer supply topology is required for '.$transformer['code'].'.');
            $aliases = [];
            foreach ($network['source_nodes'] as $sourceNode) {
                $key = (string) ($sourceNode['key'] ?? '');
                $this->require($key !== '' && ! str_starts_with($key, 'wp:') && ! isset($aliases[$key]), 'Source node alias must be unique and must not start with wp:.');
                $point = $this->point($waypoints, $sourceNode['waypoint_id'] ?? null);
                $id = $this->id('n', $revision->batch_id.':source:'.$key);
                $aliases[$key] = $id;
                $xy = $this->projection->project($point['latitude'], $point['longitude'], $epsg);
                $node = array_replace($base['Node'], ['NodeId' => $id, 'X' => $xy['x'], 'Y' => $xy['y'], 'Description' => $key]);
                $this->require(! isset($nodes[$id]) || $nodes[$id] === $node, 'Consolidated source node alias has conflicting data.');
                $nodes[$id] = $node;
            }
            $ensureWaypoint = function ($waypointId) use (&$nodes, &$aliases, $waypoints, $base, $epsg, $revision, $identityAliases): string {
                $requested = $waypointId;
                $waypointId = $identityAliases[(string) $waypointId] ?? $waypointId;
                $point = $this->point($waypoints, $waypointId);
                $key = 'wp:'.$point['id'];
                $id = $this->id('n', $revision->batch_id.':waypoint:'.$point['id']);
                $xy = $this->projection->project($point['latitude'], $point['longitude'], $epsg);
                $node = array_replace($base['Node'], ['NodeId' => $id, 'X' => $xy['x'], 'Y' => $xy['y'], 'Description' => (string) ($point['survey_identifier'] ?? $point['name'])]);
                $this->require(! isset($nodes[$id]) || $nodes[$id] === $node, 'Survey identity has conflicting coordinates in consolidated export.');
                $nodes[$id] = $node;
                $aliases[$key] = $id;
                $aliases['wp:'.$requested] = $id;

                return $id;
            };
            $root = $ensureWaypoint($transformer['source_waypoint_id'] ?? null);
            foreach ($transformer['sections'] ?? [] as $section) {
                $ensureWaypoint($section['start_waypoint_id'] ?? null);
                $ensureWaypoint($section['end_waypoint_id'] ?? null);
            }
            foreach ($network['source_sections'] as $sourceSection) {
                foreach (['from', 'to'] as $end) {
                    $ref = (string) ($sourceSection[$end] ?? '');
                    if (str_starts_with($ref, 'wp:')) {
                        $ensureWaypoint(substr($ref, 3));
                    }
                }
            }
            $feederId = $aliases[$network['feeder_node'] ?? ''] ?? null;
            $this->require($feederId !== null, 'Approved feeder source alias is missing.');
            $feeder = array_replace($base['InstFeeders'], $network['feeder_fields'] ?? [], ['FeederId' => $feederId]);
            $this->require(! empty($feeder['SubstationId']) && (float) ($feeder['NominalKvll'] ?? 0) === (float) $settings['nominal_voltage_kv'], 'Approved feeder substation and nominal voltage are required.');
            $this->require(! isset($feeders[$feederId]) || $feeders[$feederId] === $feeder, 'Consolidated feeder settings conflict.');
            $feeders[$feederId] = $feeder;
            $sourceSectionIds = [];
            $edges = [];
            foreach ($network['source_sections'] as $supply) {
                $key = (string) ($supply['key'] ?? '');
                $this->require($key !== '' && ! isset($sourceSectionIds[$key]), 'Source section keys must be unique.');
                $from = $aliases[$supply['from'] ?? ''] ?? null;
                $to = $aliases[$supply['to'] ?? ''] ?? null;
                $this->require($from && $to && $from !== $to, 'Source section endpoints must resolve to distinct approved nodes.');
                $id = $this->id('s', $revision->batch_id.':transformer:'.$transformer['id'].':source:'.$key);
                $sourceSectionIds[$key] = $id;
                $row = array_replace($base['InstSection'], $supply['fields'] ?? [], ['SectionId' => $id, 'FeederId' => $feederId, 'FromNodeId' => $from, 'ToNodeId' => $to]);
                $this->validateSection($row, $template);
                $tables['InstSection'][] = $row;
                $edges[] = [$from, $to];
            }
            $primarySection = $sourceSectionIds[$network['transformer_section'] ?? ''] ?? null;
            $this->require($primarySection !== null, 'Primary transformer section must refer to an approved source section.');
            $primary = array_replace($base['InstPrimaryTransformers'], $network['transformer_fields'] ?? [], ['SectionId' => $primarySection, 'UniqueDeviceId' => $transformer['code']]);
            $this->require(! empty($primary['TransformerType']) && isset($primary['SpecNomKv']) && isset($primary['UseInstanceImpedance']), 'Explicit transformer library type, nominal voltage and impedance mode are required.');
            $this->require(in_array($primary['TransformerType'], $template->metadata['equipment_references']['transformers'] ?? [], true), 'Transformer type is absent from the template reviewed equipment references.');
            if ((int) $primary['UseInstanceImpedance'] === 1) {
                $this->require((float) ($primary['PercentImpedance'] ?? 0) > 0 && isset($primary['PercentResistance']), 'Approved instance transformer impedance and resistance are required.');
            }
            $tables['InstPrimaryTransformers'][] = $primary;
            foreach ($transformer['sections'] ?? [] as $section) {
                $sectionPhases = $this->surveyPhaseCode($section['phases']);
                $from = $aliases['wp:'.$section['start_waypoint_id']];
                $to = $aliases['wp:'.$section['end_waypoint_id']];
                $id = $this->id('s', $revision->batch_id.':section:'.$section['id']);
                $points = [$this->point($waypoints, $section['start_waypoint_id'])];
                foreach ($section['geometry'] ?? [] as $point) {
                    $points[] = $point;
                }
                $points[] = $this->point($waypoints, $section['end_waypoint_id']);
                $length = $this->projection->length($points, $epsg);
                $lengthSource = count($points) > 2 ? 'survey_geometry' : 'endpoint_estimate';
                if (($section['measured_length_m'] ?? null) !== null) {
                    $this->require(! empty($section['length_approved_by']) && ! empty($section['measured_length_reason']), 'Measured length override needs verifier approval and a reason.');
                    $length = (float) $section['measured_length_m'];
                    $lengthSource = 'approved_measured';
                }
                $conductors = $section['conductors'] ?? [];
                $row = array_replace($base['InstSection'], [
                    'SectionId' => $id, 'FeederId' => $feederId, 'FromNodeId' => $from, 'ToNodeId' => $to,
                    'SectionPhases' => $sectionPhases, 'SectionLength_MUL' => $length,
                    'PhaseConductorId' => $conductors['R'] ?? $conductors['r'] ?? null, 'PhaseConductor2Id' => $conductors['Y'] ?? $conductors['y'] ?? null,
                    'PhaseConductor3Id' => $conductors['B'] ?? $conductors['b'] ?? null, 'NeutralConductorId' => $conductors['N'] ?? $conductors['neutral'] ?? null,
                    'ConfigurationId' => $section['equipment_ref'] ?? null,
                ]);
                $this->validateSection($row, $template);
                $tables['InstSection'][] = $row;
                $edges[] = [$from, $to];
                foreach ($section['consumers'] ?? [] as $consumer) {
                    // A manually verified zero count carries no modeled demand. Preserve its source revision only.
                    if ($consumer['count'] !== null && (int) $consumer['count'] === 0 && empty($consumer['demand'])) {
                        continue;
                    }
                    $tables['Loads'][] = $this->load($consumer, $id, $base['Loads'], $sectionPhases);
                }
                $provenance[] = ['section_id' => $id, 'survey_section_id' => $section['id'], 'pdf_id' => $section['source_pdf_id'] ?? null, 'page' => $section['source_page'] ?? null, 'row' => $section['source_row'] ?? null, 'length_source' => $lengthSource];
            }
            $reachable = [$feederId => true];
            do {
                $before = count($reachable);
                foreach ($edges as [$from, $to]) {
                    if (isset($reachable[$from])) {
                        $reachable[$to] = true;
                    }
                    if (isset($reachable[$to])) {
                        $reachable[$from] = true;
                    }
                }
            } while (count($reachable) !== $before);
            $this->require(isset($reachable[$root]), 'Transformer is disconnected from its approved feeder source.');
            foreach ($edges as [$from, $to]) {
                $this->require(isset($reachable[$from]) && isset($reachable[$to]), 'A source or survey section is disconnected from its feeder.');
            }
        }
        $tables['Node'] = array_values($nodes);
        $tables['InstFeeders'] = array_values($feeders);
        $parent = [];
        $pairs = [];
        foreach ($tables['InstSection'] as $section) {
            $from = $section['FromNodeId'];
            $to = $section['ToNodeId'];
            $pair = [$from, $to];
            sort($pair, SORT_STRING);
            $key = implode('|', $pair);
            $this->require($from !== $to && ! isset($pairs[$key]), 'Approved source mapping creates a self-connected or duplicate section.');
            $pairs[$key] = true;
            $parent[$from] ??= $from;
            $parent[$to] ??= $to;
            $fromRoot = $from;
            while ($parent[$fromRoot] !== $fromRoot) {
                $fromRoot = $parent[$fromRoot];
            }
            $toRoot = $to;
            while ($parent[$toRoot] !== $toRoot) {
                $toRoot = $parent[$toRoot];
            }
            $this->require($fromRoot !== $toRoot, 'Approved source mapping creates a cycle requiring engineering correction.');
            $parent[$toRoot] = $fromRoot;
        }

        return [
            'payload_version' => 1, 'mapping_version' => self::VERSION, 'idempotency_key' => $idempotencyKey,
            'revision' => ['id' => $revision->id, 'number' => $revision->number, 'sha256' => $revision->sha256],
            'template' => ['code' => $template->code, 'version' => $template->version, 'sha256' => $template->sha256, 'synergee_version' => $template->synergee_version, 'permitted_static_tables' => $template->metadata['permitted_static_tables'] ?? []],
            'settings' => ['epsg' => $epsg, 'configuration_revision' => $configuration['revision'] ?? null, 'electrical' => $settings],
            'tables' => $tables, 'provenance' => $provenance,
            'sources' => array_map(fn ($source) => array_intersect_key($source, array_flip(['id', 'kind', 'original_name', 'sha256', 'bytes', 'version', 'uploaded_by'])), $snapshot['sources'] ?? []),
            'pv_records' => array_merge(...array_map(fn ($t) => $t['pv_records'] ?? [], $transformers)),
            'entry_rows' => array_merge(...array_map(fn ($t) => $t['entry_rows'] ?? [], $transformers)),
            'engineering_acceptance' => 'pending_synergee_analysis',
        ];
    }

    private function load(array $consumer, string $sectionId, array $base, string $phases): array
    {
        $demand = $consumer['demand'] ?? [];
        $this->require(! empty($demand['approved_by']) && ! empty($demand['method']) && ! empty($demand['evidence']), 'Every load needs an approved measured value or documented estimation method.');
        $row = array_replace($base, ['SectionId' => $sectionId, 'Description' => (string) $consumer['category']]);
        $count = 0;
        foreach (['R', 'Y', 'B'] as $index => $phase) {
            $values = $demand['phase_values'][$phase] ?? [];
            foreach (['customers' => 'Customers', 'kw' => 'Kw', 'kvar' => 'Kvar', 'kva' => 'Kva'] as $name => $column) {
                $value = $values[$name] ?? null;
                if (! str_contains($phases, $phase) && $value === null) {
                    // Preserve an absent inactive phase as database NULL, without inheriting zero schema defaults.
                    $row['Phase'.($index + 1).$column] = null;

                    continue;
                }
                $this->require(is_numeric($value) && is_finite((float) $value) && ($name === 'kvar' || (float) $value >= 0), 'Load phase values must be explicit and finite; customers, kW and kVA must be nonnegative. Approved measured kvar may be signed.');
                $this->require(str_contains($phases, $phase) || (float) $value === 0.0, 'A load cannot be allocated to a disconnected phase.');
                $row['Phase'.($index + 1).$column] = (float) $value;
            }
            $count += (float) ($values['customers'] ?? 0);
        }
        $this->require($consumer['count'] !== null && abs($count - (float) $consumer['count']) < 1e-8, 'Approved load phase customer counts differ from the survey count.');

        return $row;
    }

    private function validateSection(array $row, Template $template): void
    {
        $this->require(isset($row['SectionLength_MUL']) && is_numeric($row['SectionLength_MUL']) && (float) $row['SectionLength_MUL'] >= 0, 'Explicit section length in metres is required.');
        $this->require($this->validPhaseCode($row['SectionPhases'] ?? null), 'Section phases must use fixed R/Y/B/N positions, with spaces for absent phases (for example R BN or   BN).');
        foreach (['R' => 'PhaseConductorId', 'Y' => 'PhaseConductor2Id', 'B' => 'PhaseConductor3Id', 'N' => 'NeutralConductorId'] as $phase => $column) {
            $value = $row[$column] ?? null;
            if (str_contains($row['SectionPhases'], $phase)) {
                $this->require($value && in_array($value, $template->metadata['equipment_references']['conductors'] ?? [], true), 'Conductor '.$phase.' needs an approved equipment-library reference.');
            } else {
                $this->require($value === null || $value === '', 'Conductor supplied for a disconnected section phase.');
            }
        }
        $this->require(! empty($row['ConfigurationId']) && in_array($row['ConfigurationId'], $template->metadata['equipment_references']['configurations'] ?? [], true), 'Section configuration needs an approved equipment-library reference.');
    }

    private function point(array $waypoints, mixed $id): array
    {
        $point = $waypoints[(string) $id] ?? null;
        $this->require($point !== null, 'Mapped waypoint is absent from the approved revision.');
        if (! empty($point['correction'])) {
            $this->require(! empty($point['correction_approved_by']) && ! empty($point['correction_approved_at']), 'Coordinate correction needs verifier approval.');
            $point['latitude'] = $point['correction']['latitude'] ?? null;
            $point['longitude'] = $point['correction']['longitude'] ?? null;
        }
        $this->require(is_numeric($point['latitude']) && is_numeric($point['longitude']) && abs((float) $point['latitude']) <= 90 && abs((float) $point['longitude']) <= 180, 'Approved coordinates are missing or out of range.');
        $point['latitude'] = (float) $point['latitude'];
        $point['longitude'] = (float) $point['longitude'];

        return $point;
    }

    private function surveyPhaseCode(array|string $phases): string
    {
        if (is_string($phases)) {
            $this->require($this->validPhaseCode($phases), 'A section phase string must preserve the fixed R/Y/B/N positions.');

            return rtrim($phases, ' ');
        }
        foreach ($phases as $phase) {
            $this->require(is_string($phase) && in_array($phase, ['R', 'Y', 'B', 'N'], true), 'Survey section phases must contain only R, Y, B and N.');
        }
        $this->require(count($phases) === count(array_unique($phases)), 'Survey section phases must not contain duplicates.');
        $code = rtrim(implode('', array_map(fn ($phase) => in_array($phase, $phases, true) ? $phase : ' ', ['R', 'Y', 'B', 'N'])), ' ');
        $this->require($this->validPhaseCode($code), 'A survey section must include at least one connected R, Y or B phase.');

        return $code;
    }

    private function validPhaseCode(mixed $code): bool
    {
        return is_string($code) && strlen($code) >= 1 && strlen($code) <= 4
            && preg_match('/^[R ][Y ][B ][N ]$/', str_pad($code, 4, ' ')) === 1
            && strpbrk($code, 'RYB') !== false;
    }

    private function id(string $prefix, string $identity): string
    {
        return $prefix.substr(hash('sha256', $identity), 0, 30);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }
}
