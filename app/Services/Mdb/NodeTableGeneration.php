<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Final MDB placement only: never modifies survey evidence, topology or span lengths. */
class NodeTableGeneration
{
    public function __construct(private readonly ProjectionService $projection) {}

    public function apply(array $tables, array $contexts, array $sources, int $epsg, array $snapshot = []): array
    {
        $original = array_column($tables['Node'], null, 'NodeId');
        $replacements = $extra = [];
        foreach ($contexts as $context) {
            ['transformer' => $transformer, 'waypoints' => $points, 'network' => $network, 'aliases' => $aliases] = $context;
            $rows = $transformer['entry_rows'] ?? [];
            // Historical networks without complete survey identities keep their established mapping.
            if (! collect($rows)->contains(fn ($row) => isset($row['composite_identifier']))) {
                continue;
            }
            $label = 'Transformer '.$transformer['code'];
            $this->check($epsg === 32643, $label.': Node generation requires WGS 84 / UTM Zone 43N (EPSG:32643).');
            $pad = $this->mounting($transformer, $sources, $snapshot);
            $pair = (new SurveyWaypoint)->firstPair($transformer);
            $this->check($pair['start'] && $pair['end'], $label.': a unique first S/E pair is required.');
            $firstSection = $this->section($transformer, $pair['end']);
            $firstEnd = $this->xy($points[(string) $firstSection['end_waypoint_id']]);
            $startId = $this->identifier($pair['start'], $label);
            $feeder = trim((string) ($transformer['header']['feeder_name'] ?? ''));
            $this->check($feeder !== '' && mb_strlen($feeder) <= 32, $label.': the verified feeder name must fit NodeId Text(32).');
            $feederOld = $aliases[$network['feeder_node']] ?? null;
            $this->check(isset($original[$feederOld]), $label.': feeder node is unresolved.');
            $this->replace($replacements, $feederOld, $this->row($feeder, $firstEnd, -0.02, $original[$feederOld]['Description'], $pad));

            // Locate the transformer terminal from the existing supply topology, not sorted waypoint names.
            $link = collect($network['source_sections'])->firstWhere('key', $network['transformer_section']);
            $terminal = null;
            foreach (['from', 'to'] as $end) {
                $ref = $link[$end] ?? '';
                if (! str_starts_with($ref, 'wp:') && isset($aliases[$ref]) && $aliases[$ref] !== $feederOld) {
                    $terminal = $aliases[$ref];
                    break;
                }
            }
            if ($terminal !== null) {
                $this->replace($replacements, $terminal, $this->row($startId.'.1', $firstEnd, -0.01, $original[$terminal]['Description'], $pad));
            } else {
                $extra[] = $this->row($startId.'.1', $firstEnd, -0.01, '', $pad);
            }
            foreach ($rows as $entry) {
                $id = $this->identifier($entry, $label);
                $section = $this->section($transformer, $entry);
                $end = match ($entry['designation'] ?? '') {
                    'S' => 'start', 'E' => 'end', default => null,
                };
                $this->check($end !== null, $label.': invalid S/E designation.');
                $pointId = (string) $section[$end.'_waypoint_id'];
                $old = $aliases['wp:'.$pointId] ?? null;
                $this->check(isset($original[$old], $points[$pointId]), $label.': waypoint '.$entry['waypoint_reference'].' has no mapped Node.');
                $identity = $points[$pointId]['survey_identifier'] ?? null;
                $this->check(! is_string($identity) || preg_match('/^\d{11}$/D', $identity) !== 1 || $identity === $entry['composite_identifier'],
                    $label.': row identity conflicts with its mapped waypoint.');
                $firstStart = $entry['designation'] === 'S' && $entry['pair_number'] == $pair['number'];
                $xy = $firstStart ? $firstEnd : $this->xy($points[$pointId]);
                $this->replace($replacements, $old, $this->row($id, $xy, $firstStart ? -0.02 : 0.0, $original[$old]['Description'], $pad));
                if ($end === 'end') {
                    $connections = (new ConsumerPhaseConnections)->build($section, $rows, $transformer['pv_records'] ?? [], $label.', E '.$entry['waypoint_reference']);
                    foreach ($connections as $connection) {
                        // Decimal suffixes are text: .1 and .10 are distinct identifiers.
                        $extra[] = $this->row($id.'.'.$connection['suffix'], $xy, 0.01, '', $pad);
                    }
                }
            }
            // Unrelated explicitly mapped source nodes retain their location and description.
            foreach (array_unique(array_values($aliases)) as $old) {
                if (! isset($replacements[$old])) {
                    $node = $original[$old];
                    $node['IsPadMountGear'] = $pad;
                    $this->replace($replacements, $old, $node);
                }
            }
        }

        $nodes = $owners = $ids = [];
        foreach ($original as $old => $node) {
            $node = $replacements[$old] ?? $node;
            $ids[$old] = $node['NodeId'];
            $this->insert($nodes, $owners, $node, (string) $old);
        }
        foreach ($extra as $index => $node) {
            $this->insert($nodes, $owners, $node, 'new:'.$index);
        }
        $tables['Node'] = array_values($nodes);
        // These are identifier substitutions only. No later-table engineering values change.
        foreach (['InstFeeders' => ['FeederId'], 'InstSection' => ['FeederId', 'FromNodeId', 'ToNodeId'],
            'InstSubstationTransformers' => ['SubTranId']] as $table => $columns) {
            foreach ($tables[$table] ?? [] as $index => $row) {
                foreach ($columns as $column) {
                    $old = $row[$column] ?? null;
                    $this->check(isset($ids[$old]), $table.'.'.$column.': unmatched Node reference '.(string) $old.'.');
                    $tables[$table][$index][$column] = $ids[$old];
                }
            }
        }

        return $tables;
    }

    private function identifier(array $row, string $label): string
    {
        $date = $row['row_date'] ?? null;
        $gps = $row['waypoint_reference'] ?? null;
        $this->check(is_string($date) && is_string($gps) && preg_match('/^\d{3}$/D', $gps) === 1,
            $label.': verified date and three text waypoint digits are required.');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $this->check($parsed && $parsed->format('Y-m-d') === $date, $label.': invalid verified row date.');
        $expected = (new SurveyWaypoint)->compose($row['group_number'] ?? null, $date, $gps);
        $this->check($expected !== null && $expected === ($row['composite_identifier'] ?? null), $label.': incomplete or conflicting survey identity for '.$gps.'.');

        return $parsed->format('jmy').$gps;
    }

    private function section(array $transformer, array $row): array
    {
        $matches = array_values(array_filter($transformer['sections'], fn ($section) => isset($row['section_id'])
            ? $section['id'] == $row['section_id']
            : ($section['original_entry']['entry_pair_number'] ?? null) == $row['pair_number']));
        $this->check(count($matches) === 1, 'Transformer '.$transformer['code'].': pair '.$row['pair_number'].' must resolve to one survey section.');

        return $matches[0];
    }

    private function mounting(array $transformer, array $sources, array $snapshot): int
    {
        $header = $transformer['header'] ?? [];
        $label = 'Transformer '.$transformer['code'];
        $flag = match (strtolower(trim((string) ($header['mounting'] ?? '')))) {
            'single pole', 'single-pole', 'double pole', 'double-pole' => 0,
            'pad', 'pad mounted', 'pad-mounted' => 1,
            default => null,
        };
        $this->check($flag !== null && in_array($header['manually_verified'] ?? false, [true, 1, '1'], true),
            $label.': mounting arrangement is missing, unreadable or ambiguous. Verify Single Pole, Double Pole or Pad against the source PDF before exporting.');
        $mobileRows = MobileSurveyProvenance::rows($snapshot);
        if ($mobileRows && ($header['verification_method'] ?? null) === 'mobile_gpx_confirmation'
            && collect($transformer['entry_rows'] ?? [])->every(fn ($row) => MobileSurveyProvenance::row($snapshot, $row, $mobileRows))) {
            $originalFlag = match ($snapshot['mobile_survey']['data']['header']['mounting'] ?? null) {
                'S.Pole', 'D.Pole' => 0, 'Pad' => 1, default => null,
            };
            $this->check($originalFlag === $flag, $label.': mounting differs from the original mobile survey header.');

            return $flag;
        }
        $pages = array_values(array_filter($header['pages'] ?? [], fn ($page) => ($page['role'] ?? null) === 'header'));
        $this->check(count($pages) === 1 && in_array($pages[0]['confirmed'] ?? false, [true, 1, '1'], true),
            $label.': confirm one source PDF header page for mounting before exporting.');
        $page = $pages[0];
        $source = collect($sources)->firstWhere('id', $page['source_pdf_id'] ?? null);
        $this->check($source && ($source['kind'] ?? '') === 'pdf' && ($source['status'] ?? '') === 'ready'
            && preg_match('/^[a-f0-9]{64}$/D', $source['sha256'] ?? '') === 1
            && is_numeric($page['page'] ?? null) && floor((float) $page['page']) == $page['page']
            && $page['page'] >= 1 && $page['page'] <= ($source['metadata']['page_count'] ?? 0),
            $label.': source PDF/page for mounting is missing or unreadable. Restore and verify the source before exporting.');

        // Normal ExportService verifies that the original PDF is retrievable and SHA-256 unchanged.
        return $flag;
    }

    private function xy(array $point): array
    {
        if (! empty($point['correction'])) {
            $this->check(! empty($point['correction_approved_by']) && ! empty($point['correction_approved_at']), 'Node coordinate correction requires approval.');
            $point = array_replace($point, $point['correction']);
        }

        return $this->projection->project($point['latitude'], $point['longitude'], 32643);
    }

    private function row(string $id, array $xy, float $offset, string $description, int $pad): array
    {
        return ['NodeId' => $id, 'X' => $xy['x'] + $offset, 'Y' => $xy['y'] + $offset,
            'Description' => $description, 'IsPadMountGear' => $pad];
    }

    private function replace(array &$replacements, string $old, array $row): void
    {
        $this->check(! isset($replacements[$old]) || $replacements[$old] === $row, 'Node '.$row['NodeId'].': conflicting identity, placement or mounting evidence.');
        $replacements[$old] = $row;
    }

    private function insert(array &$nodes, array &$owners, array $row, string $owner): void
    {
        $id = $row['NodeId'];
        $this->check(is_string($id) && $id !== '' && mb_strlen($id) <= 32, 'NodeId must be nonblank text within 32 characters.');
        $key = 'node:'.mb_strtolower($id);
        $this->check(! isset($owners[$key]) || ($owners[$key] === $owner && $nodes[$key] === $row),
            'NodeId '.$id.' collides after date/waypoint or feeder-name mapping. Resolve the affected records; identifiers were not merged.');
        $owners[$key] = $owner;
        $nodes[$key] = $row;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }
}
