<?php

namespace App\Services\Gpx;

use App\Services\Mdb\EntryTopology;
use Illuminate\Validation\ValidationException;

class SurveyConnections
{
    public function apply(array $source, string $json, ?string $code = null): array
    {
        $fail = fn ($message) => throw ValidationException::withMessages(['survey_json' => $message]);
        try {
            $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail('Choose a valid transformer or SynerGEE survey JSON.');
        }
        if (! is_array($data) || ! in_array($data['format'] ?? '', ['hazeco-transformer-data', 'hazeco-synergee-transfer'], true)) {
            $fail('Choose a transformer data or SynerGEE transfer JSON exported by this system.');
        }
        $transformers = collect($data['snapshot']['transformers'] ?? []);
        if ($code !== null && $code !== '') {
            $transformers = $transformers->filter(fn ($t) => (string) ($t['code'] ?? '') === $code);
        }
        if ($transformers->count() !== 1) {
            $fail('Enter the exact transformer code when the JSON contains multiple transformers.');
        }
        $transformer = $transformers->first();
        $rows = $transformer['entry_rows'] ?? [];
        if (! $rows || count($rows) > 20002) {
            $fail('The selected transformer needs S/E entry rows, with at most 10,000 line spans.');
        }
        $issues = app(EntryTopology::class)->issues($rows);
        if ($issues) {
            $fail(implode(' ', array_column($issues, 'message')));
        }
        $pairs = collect($rows)->groupBy('pair_number')->sortKeys(SORT_NUMERIC);
        $used = $sequences = [];
        $root = null;
        foreach ($pairs as $number => $pair) {
            if ($pair->count() !== 2 || $pair->where('designation', 'S')->count() !== 1 || $pair->where('designation', 'E')->count() !== 1) {
                $fail('Pair '.$number.' needs exactly one S and one E row.');
            }
            $ids = [];
            foreach ($pair->sortBy('designation') as $row) {
                // First S is the transformer identity, whose location is the first E.
                if ($number == $pairs->keys()->first() && $row['designation'] === 'S') {
                    continue;
                }
                if (empty($row['composite_identifier'])) {
                    $fail('Pair '.$number.' needs complete survey identifiers (group, date and waypoint).');
                }
                $matches = array_filter($source['points'], fn ($p) => $p['kind'] === 'waypoints' && (string) $p['name'] === (string) ($row['waypoint_reference'] ?? ''));
                if (count($matches) !== 1) {
                    $fail('Pair '.$number.': waypoint '.($row['waypoint_reference'] ?? '').' is missing or ambiguous in the GPX. Supply the matching GPX with unique waypoint names.');
                }
                $point = reset($matches);
                if (! $point['valid']) {
                    $fail('Waypoint '.$point['name'].' has invalid coordinates.');
                }
                $used[$point['id']] = $point;
                $ids[$row['designation']] = $point['id'];
            }
            if ($root === null) {
                $root = $ids['E'];
            } else {
                $sequences[] = $ids['S'].' > '.$ids['E'];
            }
        }
        if (! $sequences) {
            $fail('The transformer has no line spans after its first identity/location pair.');
        }
        $excluded = count($source['points']) - count($used);
        $source['points'] = $used;
        $source['groups'] = [['id' => 'survey', 'name' => 'Transformer '.$transformer['code'], 'kind' => 'waypoints', 'points' => array_keys($used)]];
        foreach ($source['points'] as &$point) {
            $point['group'] = 'survey';
        }
        unset($point);
        $source['warnings'][] = 'Survey S/E connections imported for transformer '.$transformer['code'].'. '.$excluded.' unrelated GPX points excluded. First S/E pair locates the transformer and contributes no line span. Review connections before export.';
        $source['survey'] = ['transformer_code' => $transformer['code'], 'revision' => $data['source']['revision_number'] ?? null,
            'json_sha256' => hash('sha256', $json), 'root_point_id' => $root, 'excluded_points' => $excluded];

        return ['source' => $source, 'span_sequences' => implode("\n", $sequences)];
    }
}
