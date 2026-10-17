<?php

namespace App\Services;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\Template;
use App\Models\TransformerMdbProject;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\GenerationMode;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TransformerMdbNetworkService
{
    public const CONSUMERS = ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'];

    public function __construct(private readonly GpxWaypointService $gpx, private readonly UtmProjection $projection) {}

    public function build(TransformerMdbProject $project, ?Configuration $central = null): array
    {
        $this->require($project->feeder !== null, 'Select a feeder before building the network.');
        $simple = GenerationMode::simple($central);
        $settings = $project->export_settings;
        if ($central) {
            $this->require($central->project_id === $project->feeder->project_id, 'Select the central engineering configuration for this project.');
            $this->require($simple || ($central->approved_by && $central->approved_at), 'Central engineering configuration must be approved for this project.');
            $engineering = $central->settings['engineering'] ?? [];
            $supportedCrs = $central->epsg >= 32601 && $central->epsg <= 32660;
            $this->require($simple || $supportedCrs, 'The older transformer builder supports WGS84 northern UTM only. Use MDB Creation & Verification for the configured CRS.');
            $this->require($simple || ($central->load_assumptions['mode'] ?? '') === 'category_rates', 'The older builder requires centrally approved category rates. Use MDB Creation & Verification for explicit verified phase demand.');
            $settings = array_replace($settings, ['utm_zone' => $supportedCrs ? $central->epsg - 32600 : null, 'frequency' => $central->settings['frequency_hz'] ?? null,
                'nominal_kv' => $central->settings['nominal_voltage_kv'] ?? null, 'configuration_id' => $engineering['configuration_id'] ?? null,
                'phase_spacing_cm' => $engineering['phase_spacing_cm'] ?? null, 'neutral_spacing_cm' => $engineering['neutral_spacing_cm'] ?? null,
                'conductor_height_m' => $engineering['conductor_height_m'] ?? null, 'blank_consumers_zero' => $engineering['blank_consumers_zero'] ?? false, 'engineering_reviewed' => (bool) ($central->approved_by && $central->approved_at),
                'central_configuration' => $central->toArray()]);
            $type = null;
            foreach ($engineering['transformer_types'] ?? [] as $capacity => $reference) {
                if ((float) $capacity === (float) ($project->header['capacity_kva'] ?? 0)) {
                    $type = $reference;
                }
            }
            $this->require($simple || $type !== null, 'Map this transformer capacity in Engineering Configuration before export.');
            $settings['transformer_type'] = $type;
        }
        $settings['simple_mdb_generation'] = $simple;
        $approvedDefaults = ! $simple || ! empty($settings['engineering_reviewed']);
        $this->require($simple || ! empty($settings['engineering_reviewed']), 'Review and confirm the engineering settings before export.');
        $this->require($simple || ($project->header['capacity_kva'] ?? 0) > 0, 'Enter transformer capacity in kVA.');
        $this->require(trim($project->header['substation'] ?? '') !== '', 'Enter the substation name.');
        $this->require(count($project->rows) >= 2 && count($project->rows) % 2 === 0, 'Enter complete consecutive S/E pairs. Each S row must be followed by its E row.');
        $aliases = array_values(array_filter(array_map('trim', explode(',', $settings['transformer_waypoints'] ?? '')), fn ($value) => $value !== ''));
        $this->require($aliases !== [], 'Enter the waypoint identifiers that represent the transformer connection.');
        $zone = $simple ? ($settings['utm_zone'] ?? null) : (int) ($settings['utm_zone'] ?? 43);
        $rootLat = $settings['transformer_latitude'] ?? null;
        $rootLon = $settings['transformer_longitude'] ?? null;
        $this->require($rootLat !== null && $rootLon !== null, 'Enter confirmed transformer latitude and longitude. Use its GPX waypoint or existing GIS reference.');
        $root = $this->project((float) $rootLat, (float) $rootLon, $zone);
        $observedPoints = [];
        $defaults = json_decode(file_get_contents(resource_path('mdb/defaults.json')), true, 512, JSON_THROW_ON_ERROR);
        if ($central) {
            $defaults = $central->settings['mapping']['table_values'] ?? [];
        }
        foreach (['SAI_Control', 'Node', 'InstFeeders', 'InstSection', 'InstPrimaryTransformers', 'Loads'] as $table) {
            $defaults[$table] ??= [];
        }
        $feederId = $this->identifier($project->feeder->feeder_name);
        $this->require(trim($feederId) !== '', 'Enter a feeder name.');
        $code = $project->transformer_code;
        $baseId = preg_replace('/^T[-\s]*/i', '', $code);
        $this->require(trim($baseId) !== '', 'Enter a transformer code.');
        // Dedicated namespace prevents collisions with waypoint-derived IDs.
        $sourceId = $feederId;
        $this->require(! in_array($sourceId, ['TX-HV', 'TX-LV'], true), 'Feeder name conflicts with a reserved transformer node ID.');
        $primaryId = 'TX-HV';
        $secondaryId = 'TX-LV';
        $feederCode = $project->feeder->feeder_code;
        $primarySectionId = $this->identifier($feederCode.'-'.$baseId.'.1');
        $secondarySectionId = $this->identifier($feederCode.'-'.$baseId.'.2');
        $mounting = trim($project->header['mounting'] ?? '');
        $padFlag = $simple && ! $approvedDefaults && $mounting === '' ? null : (int) ($mounting === 'Pad');
        $supplyPhases = $simple && ! $approvedDefaults && (empty($engineering['supply_conductor_id']) || empty($defaults['InstPrimaryTransformers']['ConnectedPhases'])) ? '' : 'RYB';
        $secondaryPhases = $simple && ! $approvedDefaults && (empty($engineering['secondary_conductor_id']) || empty($defaults['InstPrimaryTransformers']['ConnectedPhases'])) ? '' : 'RYBN';
        $nodes = [
            $sourceId => ['NodeId' => $sourceId] + $root + ['Description' => $feederId, 'IsPadMountGear' => $padFlag === null ? null : 0],
            $primaryId => ['NodeId' => $primaryId] + $root + ['Description' => $code, 'IsPadMountGear' => $padFlag === null ? null : 0],
            $secondaryId => ['NodeId' => $secondaryId] + $root + ['Description' => $code, 'IsPadMountGear' => $padFlag],
        ];
        $sections = [
            $this->section($defaults['InstSection'], $settings, $primarySectionId, $feederId, $sourceId, $primaryId, $supplyPhases, ['r' => $central ? ($engineering['supply_conductor_id'] ?? null) : 'DOG', 'y' => $central ? ($engineering['supply_conductor_id'] ?? null) : 'DOG', 'b' => $central ? ($engineering['supply_conductor_id'] ?? null) : 'DOG', 'neutral' => ''], 0, 'Transformer supply'),
            $this->section($defaults['InstSection'], $settings, $secondarySectionId, $feederId, $primaryId, $secondaryId, $secondaryPhases, array_fill_keys(['r', 'y', 'b', 'neutral'], $central ? ($engineering['secondary_conductor_id'] ?? null) : 'ANT'), 0, 'Transformer secondary connection'),
        ];
        $links = [];
        $loads = [];
        $auditRows = [];
        $pairRows = [];
        for ($index = 0; $index < count($project->rows); $index += 2) {
            $start = $project->rows[$index];
            $end = $project->rows[$index + 1];
            $label = 'Pair '.($index / 2 + 1);
            $this->require(($start['se'] ?? '') === 'S' && ($end['se'] ?? '') === 'E', "{$label}: S must be followed by E.");
            $from = $this->node($start, $aliases, $project->gpx_waypoints ?? [], $zone, $nodes, $observedPoints, $approvedDefaults, $label.' S');
            $to = $this->node($end, $aliases, $project->gpx_waypoints ?? [], $zone, $nodes, $observedPoints, $approvedDefaults, $label.' E');
            foreach ([$start, $end] as $offset => $row) {
                $node = $offset === 0 ? $from : $to;
                $auditRows[] = ['RowNumber' => $index + $offset + 1, 'NodeId' => $node, 'SourceJson' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            }
            if ($from === $secondaryId && $to === $secondaryId) {
                $this->require($this->consumerCount($start['consumers'] ?? []) === 0 && $this->consumerCount($end['consumers'] ?? []) === 0, "{$label}: transformer connection rows cannot carry consumer counts. Enter them on a distribution E row.");
                $pairRows[] = ['pair' => $index / 2 + 1, 'from' => $start['gps_waypoint'], 'to' => $end['gps_waypoint'], 'length' => 0, 'note' => 'Transformer connection; retained in survey rows'];

                continue;
            }
            $this->require($from !== $to, "{$label}: a distribution section cannot start and end at the same node.");
            $this->require($to !== $secondaryId, "{$label}: distribution sections must point away from the transformer. Reverse this S/E pair.");
            $this->require(! isset($links[$to]), "{$label}: this endpoint already has an incoming section. Check duplicate pairs or loop connections.");
            $this->require($this->consumerCount($start['consumers'] ?? []) === 0, "{$label}: put consumer counts on the E row; S row counts cannot be assigned unambiguously.");
            $phases = $simple && trim($start['phase'] ?? '') === '' ? '' : $this->phases($start['phase'] ?? '', $label);
            $conductors = [];
            foreach (['r', 'y', 'b', 'neutral'] as $phase) {
                $value = trim($start['conductor_'.$phase] ?? '');
                $conductors[$phase] = $this->conductor($value);
                if ($phase !== 'neutral' && str_contains($phases, strtoupper($phase))) {
                    $this->require($simple || $conductors[$phase] !== '', "{$label}: enter conductor {$phase} for the connected phase.");
                }
            }
            if ($phases !== '' && ($conductors['neutral'] !== '' || ($simple && str_contains(strtoupper($start['phase'] ?? ''), 'N')))) {
                $phases .= 'N';
            }
            $this->require($simple || ! str_contains(strtoupper($start['phase'] ?? ''), 'N') || $conductors['neutral'] !== '', "{$label}: enter the neutral conductor when phase N is specified.");
            if ($simple) {
                foreach ($conductors as $phase => $value) {
                    if ($value === '' && str_contains($phases, $phase === 'neutral' ? 'N' : strtoupper($phase))) {
                        $conductors[$phase] = null;
                    }
                }
            }
            $length = $zone === null ? null : hypot($nodes[$to]['X'] - $nodes[$from]['X'], $nodes[$to]['Y'] - $nodes[$from]['Y']);
            $sectionId = $this->identifier($feederCode.'-L'.str_pad((string) ($index / 2 + 1), 4, '0', STR_PAD_LEFT));
            $sections[] = $this->section($defaults['InstSection'], $settings, $sectionId, $feederId, $from, $to, $phases, $conductors, $length, 'Secondary Distribution');
            $links[$to] = $from;
            $load = $this->load($defaults['Loads'], $sectionId, $end, $phases, $settings, $label);
            $loads[] = $load;
            $pairRows[] = ['pair' => $index / 2 + 1, 'from' => $start['gps_waypoint'], 'to' => $end['gps_waypoint'], 'length' => $length === null ? null : round($length, 2), 'note' => $phases];
        }
        $this->require($links !== [], 'Enter at least one distribution S/E pair.');
        foreach ($links as $node => $parent) {
            $visited = [];
            while ($parent !== $secondaryId) {
                $this->require(! isset($visited[$parent]) && isset($links[$parent]), "Node {$node} is disconnected from the transformer or belongs to a loop. Check the S/E waypoints and transformer aliases.");
                $visited[$parent] = true;
                $parent = $links[$parent];
            }
        }
        $year = (int) $project->survey_date->format('Y');
        $control = $defaults['SAI_Control'];
        $control['Frequency'] = (int) ($settings['frequency'] ?? 50);
        $control['ProjectionFile'] = 'WGS 1984 UTM Zone '.$zone.'N';
        $control['ProjectionWKT'] = '';
        if ($central) {
            $control = $defaults['SAI_Control'];
            if ($simple) {
                $control['Frequency'] = $settings['frequency'] ?? $control['Frequency'] ?? null;
                $control['ProjectionFile'] = $engineering['crs_name'] ?? $control['ProjectionFile'] ?? null;
                $control['ProjectionWKT'] = $engineering['projection_wkt'] ?? $control['ProjectionWKT'] ?? null;
            }
        }
        foreach (['B' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10] as $suffix => $offset) {
            $control['MYM_YearDesc'.((string) $suffix === '10' ? '10' : '_'.$suffix)] = (string) ($year + $offset);
        }
        $voltage = $simple ? $this->number($settings['nominal_kv']) : (float) ($settings['nominal_kv'] ?? 11);
        $feeder = array_replace($defaults['InstFeeders'], ['FeederId' => $feederId, 'SubstationId' => $this->identifier($project->header['substation']),
            'NominalKvll' => $voltage, 'BusVoltageLevel' => $voltage, 'ByPhVoltLevelPh1' => $voltage, 'ByPhVoltLevelPh2' => $voltage, 'ByPhVoltLevelPh3' => $voltage, 'Note_' => mb_substr($project->remarks ?? '', 0, 100)]);
        $transformer = array_replace($defaults['InstPrimaryTransformers'], ['SectionId' => $primarySectionId, 'UniqueDeviceId' => $code,
            'TransformerType' => $simple ? ($settings['transformer_type'] ?: null) : ($settings['transformer_type'] ?: $project->header['capacity_kva'].' KVA'), 'Note_' => mb_substr($project->header['transformer_make'] ?? '', 0, 100)]);
        $header = ['transformer_code' => $code, 'feeder_code' => $feederCode, 'survey_date' => $project->survey_date->toDateString(),
            'header' => $project->header, 'remarks' => $project->remarks, 'export_settings' => $settings, 'source_field_survey_id' => $project->source_field_survey_id, 'revision' => $project->revision];

        $network = ['simple_mdb_generation' => $simple, 'pairs' => $pairRows, 'node_count' => count($nodes), 'section_count' => count($sections), 'warnings' => [
            $simple ? 'Simple MDB Generation is ON. Missing engineering values remain blank; identifiers and network connectivity are validated.' : 'S/E pairs define a radial network; consumer loads are allocated to connected phases using the entered kVA per consumer.',
            'Solar entries and original survey observations remain in the application and export audit; generator locations and electrical models must be assigned in SynerGEE.',
            'Conductor and transformer type IDs must exist in the target SynerGEE equipment library.'], 'tables' => [
                'SAI_Control' => [$control], 'Node' => array_values($nodes), 'InstFeeders' => [$feeder], 'InstSection' => $sections,
                'InstPrimaryTransformers' => [$transformer], 'Loads' => $loads,
            ], 'survey_audit' => ['header' => $header, 'rows' => $auditRows, 'solar' => $project->solar]];
        if ($central) {
            $template = Template::find($central->settings['template_id'] ?? null);
            $this->require($simple || ($template && $template->active && $template->approved_by && $template->approved_at), 'The configured MDB template is no longer approved.');
            $libraries = $template?->metadata['equipment_references'] ?? [];
            $this->require($simple || in_array($settings['transformer_type'], $libraries['transformers'] ?? [], true), 'Transformer type is no longer present in the approved library.');
            foreach ($simple ? [] : $sections as $section) {
                foreach (['PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id', 'NeutralConductorId'] as $column) {
                    $reference = $section[$column] ?? null;
                    $this->require($reference === null || $reference === '' || in_array($reference, $libraries['conductors'], true), 'All conductors must belong to the centrally approved equipment library.');
                }
            }
            foreach ($simple ? [] : ($project->solar ?? []) as $pv) {
                $this->require((float) ($pv['installed_pv_kw'] ?? 0) === 0, 'Use MDB Creation & Verification for reviewed PV generator mapping or exclusion.');
            }
            if ($template && $template->active && (($template->approved_by && $template->approved_at) || ($simple && ($template->metadata['system_reference_schema'] ?? false) === true))) {
                $network['engineering_template_id'] = $template->id;
                $network['engineering_template_sha256'] = $template->sha256;
            }
        }
        $limits = json_decode(file_get_contents(resource_path('mdb/string-limits.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($network['tables'] as $table => $tableRows) {
            foreach ($tableRows as $tableRow) {
                foreach ($tableRow as $column => $value) {
                    if (is_string($value) && isset($limits[$table][$column])) {
                        $this->require(mb_strlen($value) <= $limits[$table][$column], "{$table}.{$column} exceeds the MDB field limit of {$limits[$table][$column]} characters. Shorten its library ID or value before export.");
                    }
                }
            }
        }

        return $network;
    }

    private function node(array $row, array $aliases, array $waypoints, ?int $zone, array &$nodes, array &$observedPoints, bool $approvedDefaults, string $label): string
    {
        $name = trim($row['gps_waypoint'] ?? '');
        $this->require($name !== '' && ! empty($row['date']), "{$label}: enter the waypoint and observation date.");
        if (in_array($name, $aliases, true)) {
            return 'TX-LV';
        }
        $hasLat = isset($row['latitude']);
        $hasLon = isset($row['longitude']);
        $this->require($hasLat === $hasLon, "{$label}: enter both latitude and longitude or leave both blank to link GPX.");
        $point = $hasLat ? $row : $this->gpx->resolve($waypoints, $name, $row['date']);
        $xy = $this->project((float) $point['latitude'], (float) $point['longitude'], $zone);
        // Date + exact text waypoint preserves leading zeros and distinguishes reused IDs.
        $id = $this->identifier($row['date'].':'.$name);
        $this->require(! isset($nodes[$id]) || isset($observedPoints[$id]), "{$label}: waypoint identifier conflicts with a reserved network node.");
        if (isset($nodes[$id])) {
            $previous = $observedPoints[$id];
            $distance = $zone === null
                ? hypot(((float) $point['latitude'] - $previous['latitude']) * 111320, ((float) $point['longitude'] - $previous['longitude']) * 111320 * cos(deg2rad((float) $point['latitude'])))
                : hypot($xy['X'] - $nodes[$id]['X'], $xy['Y'] - $nodes[$id]['Y']);
            $this->require($distance < 1, "{$label}: repeated waypoint has conflicting coordinates.");
        } else {
            $padFlag = $approvedDefaults ? 0 : match (trim($row['equipment_type'] ?? '')) {
                'Pad' => 1, 'S.Pole', 'D.Pole' => 0, default => null,
            };
            $nodes[$id] = ['NodeId' => $id] + $xy + ['Description' => $name, 'IsPadMountGear' => $padFlag];
            $observedPoints[$id] = $point;
        }

        return $id;
    }

    private function section(array $defaults, array $settings, string $id, string $feeder, string $from, string $to, string $phases, array $conductors, ?float $length, string $description): array
    {
        $phaseConductor = '';
        foreach (['r', 'y', 'b'] as $phase) {
            if (str_contains($phases, strtoupper($phase))) {
                $phaseConductor = $conductors[$phase];
                break;
            }
        }

        if (isset($settings['central_configuration'])) {
            $phaseConductor = $conductors['r'];
            $phases = rtrim(implode('', array_map(fn ($phase) => str_contains($phases, $phase) ? $phase : ' ', ['R', 'Y', 'B', 'N'])));
        }

        $simple = ! empty($settings['simple_mdb_generation']);

        return array_replace($defaults, ['SectionId' => $id, 'FeederId' => $feeder, 'FromNodeId' => $from, 'ToNodeId' => $to,
            'SectionPhases' => $phases, 'Description' => $description, 'PhaseConductorId' => $phaseConductor,
            'PhaseConductor2Id' => $conductors['y'], 'PhaseConductor3Id' => $conductors['b'], 'NeutralConductorId' => $conductors['neutral'],
            'SectionLength_MUL' => $length, 'ConfigurationId' => $settings['configuration_id'] ?? ($simple ? null : ''),
            'PhaseToPhaseSpacing_MUL' => $simple ? $this->number($settings['phase_spacing_cm'] ?? null) : (float) ($settings['phase_spacing_cm'] ?? 121.9),
            'PhaseToNeutralSpacing_MUL' => $simple ? $this->number($settings['neutral_spacing_cm'] ?? null) : (float) ($settings['neutral_spacing_cm'] ?? 91.4),
            'AveHeightAboveGround_MUL' => $simple ? $this->number($settings['conductor_height_m'] ?? null) : (float) ($settings['conductor_height_m'] ?? 9.1)]);
    }

    private function load(array $defaults, string $section, array $end, string $phases, array $settings, string $label): array
    {
        if (! empty($settings['simple_mdb_generation'])) {
            return $this->simpleLoad($defaults, $section, $end, $phases, $settings);
        }
        if (isset($settings['central_configuration'])) {
            $load = array_replace($defaults, ['SectionId' => $section, 'Description' => 'Centrally approved category demand']);
            foreach (['R', 'Y', 'B'] as $index => $phase) {
                foreach (['Customers', 'Kw', 'Kvar', 'Kva'] as $quantity) {
                    $load['Phase'.($index + 1).$quantity] = 0;
                }
            }
            foreach (self::CONSUMERS as $category) {
                $count = $end['consumers'][$category] ?? null;
                $this->require($count !== null || ! empty($settings['blank_consumers_zero']), $label.': resolve blank consumer counts.');
                $demand = app(EngineeringConfiguration::class)->demand((int) ($count ?? 0), $category, $phases, $settings['central_configuration']);
                foreach (['R', 'Y', 'B'] as $index => $phase) {
                    foreach (['Customers' => 'customers', 'Kw' => 'kw', 'Kvar' => 'kvar', 'Kva' => 'kva'] as $column => $key) {
                        $load['Phase'.($index + 1).$column] += $demand['phase_values'][$phase][$key] ?? 0;
                    }
                }
            }

            return $load;
        }
        $count = 0;
        $kva = 0;
        foreach (self::CONSUMERS as $category) {
            $value = $end['consumers'][$category] ?? null;
            $this->require($value !== null || ! empty($settings['blank_consumers_zero']), "{$label}: enter all E row consumer counts, or explicitly treat blank consumer cells as zero.");
            $value = (int) ($value ?? 0);
            $rate = $settings['consumer_kva'][$category] ?? null;
            $this->require($value === 0 || $rate !== null, "{$label}: enter kVA per {$category} consumer in the engineering settings.");
            $count += $value;
            $kva += $value * (float) ($rate ?? 0);
        }
        $connected = array_values(array_filter(['R', 'Y', 'B'], fn ($phase) => str_contains($phases, $phase)));
        $load = array_replace($defaults, ['SectionId' => $section, 'Description' => 'Survey consumers; category counts retained in application audit']);
        foreach (['R', 'Y', 'B'] as $index => $phase) {
            $load['Phase'.($index + 1).'Customers'] = in_array($phase, $connected, true) ? $count / count($connected) : 0;
            $load['Phase'.($index + 1).'Kva'] = in_array($phase, $connected, true) ? $kva / count($connected) : 0;
        }

        return $load;
    }

    /** Missing category demand or PF must not turn into an assumed zero demand. */
    private function simpleLoad(array $defaults, string $section, array $end, string $phases, array $settings): array
    {
        $configuration = $settings['central_configuration'];
        $assumptions = $configuration['load_assumptions'] ?? [];
        $load = array_replace($defaults, ['SectionId' => $section, 'Description' => ! empty($configuration['approved_by']) && ! empty($configuration['approved_at'])
            ? 'Centrally approved category demand' : 'Survey consumers; original counts retained in application audit']);
        $complete = ($assumptions['mode'] ?? null) === 'category_rates' && preg_match('/[RYB]/', $phases) === 1;
        foreach (self::CONSUMERS as $category) {
            $count = $end['consumers'][$category] ?? null;
            if ($count === null && empty($settings['blank_consumers_zero'])) {
                $complete = false;
            }
            if ((int) ($count ?? 0) > 0) {
                $rate = $assumptions['rates'][$category] ?? [];
                $complete = $complete && is_numeric($rate['kva'] ?? null) && $rate['kva'] >= 0
                    && is_numeric($rate['power_factor'] ?? null) && $rate['power_factor'] > 0 && $rate['power_factor'] <= 1;
            }
        }
        foreach (['R', 'Y', 'B'] as $index => $phase) {
            foreach (['Customers', 'Kw', 'Kvar', 'Kva'] as $quantity) {
                $load['Phase'.($index + 1).$quantity] = $complete ? 0 : null;
            }
        }
        if (! $complete) {
            return $load;
        }
        foreach (self::CONSUMERS as $category) {
            $count = (int) ($end['consumers'][$category] ?? 0);
            if ($count === 0) {
                continue;
            }
            // demand() reports provenance; this draft remains unapproved.
            $configuration['load_assumptions'] += ['method' => null, 'evidence' => null];
            $demand = app(EngineeringConfiguration::class)->demand($count, $category, $phases, $configuration);
            foreach (['R', 'Y', 'B'] as $index => $phase) {
                foreach (['Customers' => 'customers', 'Kw' => 'kw', 'Kvar' => 'kvar', 'Kva' => 'kva'] as $column => $key) {
                    $load['Phase'.($index + 1).$column] += $demand['phase_values'][$phase][$key] ?? 0;
                }
            }
        }

        return $load;
    }

    private function consumerCount(array $consumers): int
    {
        return array_sum(array_map(fn ($value) => (int) ($value ?? 0), $consumers));
    }

    private function phases(string $value, string $label): string
    {
        $clean = preg_replace('/[\s,\/\-]+/', '', strtoupper(trim($value)));
        $this->require($clean !== '' && preg_match('/^[RYBN]+$/', $clean) === 1, "{$label}: use phase letters R, Y, B (and optional N).");
        $result = '';
        foreach (['R', 'Y', 'B'] as $phase) {
            if (str_contains($clean, $phase)) {
                $result .= $phase;
            }
        }
        $this->require($result !== '', "{$label}: at least one live phase is required.");

        return $result;
    }

    private function identifier(string $value): string
    {
        return mb_strlen($value) <= 32 ? $value : mb_substr($value, 0, 19).'_'.substr(hash('sha256', $value), 0, 12);
    }

    private function conductor(string $value): string
    {
        return match (strtoupper($value)) {
            'A' => 'ANT', 'W' => 'WASP', 'GN' => 'GNAT', '-', '' => '', default => $value
        };
    }

    private function project(float $latitude, float $longitude, ?int $zone): array
    {
        if ($zone === null) {
            $this->require(is_finite($latitude) && is_finite($longitude) && $latitude >= 0 && $latitude <= 84 && $longitude >= -180 && $longitude <= 180, 'Coordinates are outside valid ranges.');

            return ['X' => null, 'Y' => null];
        }
        try {
            return $this->projection->project($latitude, $longitude, $zone);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['export_settings.utm_zone' => $exception->getMessage()]);
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['network' => $message]);
        }
    }
}
