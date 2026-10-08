<?php

namespace App\Services;

use App\Models\TransformerMdbProject;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TransformerMdbNetworkService
{
    public const CONSUMERS = ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st'];

    public function __construct(private readonly GpxWaypointService $gpx, private readonly UtmProjection $projection) {}

    public function build(TransformerMdbProject $project): array
    {
        $settings = $project->export_settings;
        $this->require($project->feeder !== null, 'Select a feeder before building the network.');
        $this->require(! empty($settings['engineering_reviewed']), 'Review and confirm the engineering settings before export.');
        $this->require(($project->header['capacity_kva'] ?? 0) > 0, 'Enter transformer capacity in kVA.');
        $this->require(trim($project->header['substation'] ?? '') !== '', 'Enter the substation name.');
        $this->require(count($project->rows) >= 2 && count($project->rows) % 2 === 0, 'Enter complete consecutive S/E pairs. Each S row must be followed by its E row.');
        $aliases = array_values(array_filter(array_map('trim', explode(',', $settings['transformer_waypoints'] ?? '')), fn ($value) => $value !== ''));
        $this->require($aliases !== [], 'Enter the waypoint identifiers that represent the transformer connection.');
        $zone = (int) ($settings['utm_zone'] ?? 43);
        $rootLat = $settings['transformer_latitude'] ?? null;
        $rootLon = $settings['transformer_longitude'] ?? null;
        $this->require($rootLat !== null && $rootLon !== null, 'Enter confirmed transformer latitude and longitude. Use its GPX waypoint or existing GIS reference.');
        $root = $this->project((float) $rootLat, (float) $rootLon, $zone);
        $defaults = json_decode(file_get_contents(resource_path('mdb/defaults.json')), true, 512, JSON_THROW_ON_ERROR);
        $feederId = $this->identifier($project->feeder->feeder_name);
        $code = $project->transformer_code;
        $baseId = preg_replace('/^T[-\s]*/i', '', $code);
        $this->require($baseId !== '', 'Enter a transformer code.');
        // Dedicated namespace prevents collisions with waypoint-derived IDs.
        $sourceId = $feederId;
        $this->require(! in_array($sourceId, ['TX-HV', 'TX-LV'], true), 'Feeder name conflicts with a reserved transformer node ID.');
        $primaryId = 'TX-HV';
        $secondaryId = 'TX-LV';
        $feederCode = $project->feeder->feeder_code;
        $primarySectionId = $this->identifier($feederCode.'-'.$baseId.'.1');
        $secondarySectionId = $this->identifier($feederCode.'-'.$baseId.'.2');
        $nodes = [
            $sourceId => ['NodeId' => $sourceId] + $root + ['Description' => $feederId, 'IsPadMountGear' => 0],
            $primaryId => ['NodeId' => $primaryId] + $root + ['Description' => $code, 'IsPadMountGear' => 0],
            $secondaryId => ['NodeId' => $secondaryId] + $root + ['Description' => $code, 'IsPadMountGear' => (int) (($project->header['mounting'] ?? '') === 'Pad')],
        ];
        $sections = [
            $this->section($defaults['InstSection'], $settings, $primarySectionId, $feederId, $sourceId, $primaryId, 'RYB', ['r' => 'DOG', 'y' => 'DOG', 'b' => 'DOG', 'neutral' => ''], 0, 'Transformer supply'),
            $this->section($defaults['InstSection'], $settings, $secondarySectionId, $feederId, $primaryId, $secondaryId, 'RYBN', ['r' => 'ANT', 'y' => 'ANT', 'b' => 'ANT', 'neutral' => 'ANT'], 0, 'Transformer secondary connection'),
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
            $from = $this->node($start, $aliases, $project->gpx_waypoints ?? [], $root, $zone, $nodes, $label.' S');
            $to = $this->node($end, $aliases, $project->gpx_waypoints ?? [], $root, $zone, $nodes, $label.' E');
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
            $phases = $this->phases($start['phase'] ?? '', $label);
            $conductors = [];
            foreach (['r', 'y', 'b', 'neutral'] as $phase) {
                $value = trim($start['conductor_'.$phase] ?? '');
                $conductors[$phase] = $this->conductor($value);
                if ($phase !== 'neutral' && str_contains($phases, strtoupper($phase))) {
                    $this->require($conductors[$phase] !== '', "{$label}: enter conductor {$phase} for the connected phase.");
                }
            }
            if ($conductors['neutral'] !== '') {
                $phases .= 'N';
            }
            $this->require(! str_contains(strtoupper($start['phase'] ?? ''), 'N') || $conductors['neutral'] !== '', "{$label}: enter the neutral conductor when phase N is specified.");
            $length = hypot($nodes[$to]['X'] - $nodes[$from]['X'], $nodes[$to]['Y'] - $nodes[$from]['Y']);
            $sectionId = $this->identifier($feederCode.'-L'.str_pad((string) ($index / 2 + 1), 4, '0', STR_PAD_LEFT));
            $sections[] = $this->section($defaults['InstSection'], $settings, $sectionId, $feederId, $from, $to, $phases, $conductors, $length, 'Secondary Distribution');
            $links[$to] = $from;
            $load = $this->load($defaults['Loads'], $sectionId, $end, $phases, $settings, $label);
            $loads[] = $load;
            $pairRows[] = ['pair' => $index / 2 + 1, 'from' => $start['gps_waypoint'], 'to' => $end['gps_waypoint'], 'length' => round($length, 2), 'note' => $phases];
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
        foreach (['B' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10] as $suffix => $offset) {
            $control['MYM_YearDesc'.((string) $suffix === '10' ? '10' : '_'.$suffix)] = (string) ($year + $offset);
        }
        $feeder = array_replace($defaults['InstFeeders'], ['FeederId' => $feederId, 'SubstationId' => $this->identifier($project->header['substation']),
            'NominalKvll' => (float) ($settings['nominal_kv'] ?? 11), 'BusVoltageLevel' => (float) ($settings['nominal_kv'] ?? 11), 'ByPhVoltLevelPh1' => (float) ($settings['nominal_kv'] ?? 11), 'ByPhVoltLevelPh2' => (float) ($settings['nominal_kv'] ?? 11), 'ByPhVoltLevelPh3' => (float) ($settings['nominal_kv'] ?? 11), 'Note_' => mb_substr($project->remarks ?? '', 0, 100)]);
        $transformer = array_replace($defaults['InstPrimaryTransformers'], ['SectionId' => $primarySectionId, 'UniqueDeviceId' => $code,
            'TransformerType' => $settings['transformer_type'] ?: $project->header['capacity_kva'].' KVA', 'Note_' => mb_substr($project->header['transformer_make'] ?? '', 0, 100)]);
        $header = ['transformer_code' => $code, 'feeder_code' => $feederCode, 'survey_date' => $project->survey_date->toDateString(),
            'header' => $project->header, 'remarks' => $project->remarks, 'export_settings' => $settings, 'source_field_survey_id' => $project->source_field_survey_id, 'revision' => $project->revision];

        $network = ['pairs' => $pairRows, 'node_count' => count($nodes), 'section_count' => count($sections), 'warnings' => [
            'S/E pairs define a radial network; consumer loads are allocated to connected phases using the entered kVA per consumer.',
            'Solar entries are preserved in SurveySolar; generator locations and electrical models must be assigned in SynerGEE.',
            'Conductor and transformer type IDs must exist in the target SynerGEE equipment library.'], 'tables' => [
                'SAI_Control' => [$control], 'Node' => array_values($nodes), 'InstFeeders' => [$feeder], 'InstSection' => $sections,
                'InstPrimaryTransformers' => [$transformer], 'Loads' => $loads,
                'SurveyHeader' => [['SourceJson' => json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]], 'SurveyRows' => $auditRows,
                'SurveySolar' => array_map(fn ($entry) => ['SourceJson' => json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], $project->solar),
            ]];
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

    private function node(array $row, array $aliases, array $waypoints, array $root, int $zone, array &$nodes, string $label): string
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
        if (isset($nodes[$id])) {
            $this->require(hypot($xy['X'] - $nodes[$id]['X'], $xy['Y'] - $nodes[$id]['Y']) < 1, "{$label}: repeated waypoint has conflicting coordinates.");
        } else {
            $nodes[$id] = ['NodeId' => $id] + $xy + ['Description' => $name, 'IsPadMountGear' => 0];
        }

        return $id;
    }

    private function section(array $defaults, array $settings, string $id, string $feeder, string $from, string $to, string $phases, array $conductors, float $length, string $description): array
    {
        $phaseConductor = '';
        foreach (['r', 'y', 'b'] as $phase) {
            if (str_contains($phases, strtoupper($phase))) {
                $phaseConductor = $conductors[$phase];
                break;
            }
        }

        return array_replace($defaults, ['SectionId' => $id, 'FeederId' => $feeder, 'FromNodeId' => $from, 'ToNodeId' => $to,
            'SectionPhases' => $phases, 'Description' => $description, 'PhaseConductorId' => $phaseConductor,
            'PhaseConductor2Id' => $conductors['y'], 'PhaseConductor3Id' => $conductors['b'], 'NeutralConductorId' => $conductors['neutral'],
            'SectionLength_MUL' => $length, 'ConfigurationId' => $settings['configuration_id'] ?? '',
            'PhaseToPhaseSpacing_MUL' => (float) ($settings['phase_spacing_cm'] ?? 121.9),
            'PhaseToNeutralSpacing_MUL' => (float) ($settings['neutral_spacing_cm'] ?? 91.4),
            'AveHeightAboveGround_MUL' => (float) ($settings['conductor_height_m'] ?? 9.1)]);
    }

    private function load(array $defaults, string $section, array $end, string $phases, array $settings, string $label): array
    {
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
        $load = array_replace($defaults, ['SectionId' => $section, 'Description' => 'Survey consumers; category counts retained in SurveyRows']);
        foreach (['R', 'Y', 'B'] as $index => $phase) {
            $load['Phase'.($index + 1).'Customers'] = in_array($phase, $connected, true) ? $count / count($connected) : 0;
            $load['Phase'.($index + 1).'Kva'] = in_array($phase, $connected, true) ? $kva / count($connected) : 0;
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

    private function project(float $latitude, float $longitude, int $zone): array
    {
        try {
            return $this->projection->project($latitude, $longitude, $zone);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['export_settings.utm_zone' => $exception->getMessage()]);
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['network' => $message]);
        }
    }
}
