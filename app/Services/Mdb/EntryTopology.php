<?php

namespace App\Services\Mdb;

/** Validate survey connections without guessing or rewriting handwritten observations. */
class EntryTopology
{
    public function issues(array $rows): array
    {
        $pairs = collect($rows)->groupBy('pair_number')->sortKeys(SORT_NUMERIC);
        $first = $pairs->first();
        if (! $first) {
            return [];
        }
        $key = fn ($row) => $row['composite_identifier'] ?? null;
        $root = $first->firstWhere('designation', 'E');
        $reachable = $root && $key($root) ? [$key($root) => true] : [];
        $edges = [];
        foreach ($pairs->skip(1) as $number => $pair) {
            $s = $pair->firstWhere('designation', 'S');
            $e = $pair->firstWhere('designation', 'E');
            $edges[$number] = [$s, $e];
        }
        // Use the whole entered network so out-of-order entry and existing branches are valid.
        do {
            $before = count($reachable);
            foreach ($edges as [$s, $e]) {
                if ($s && $e && $key($s) && $key($e)
                    && (isset($reachable[$key($s)]) || isset($reachable[$key($e)]))) {
                    $reachable[$key($s)] = $reachable[$key($e)] = true;
                }
            }
        } while (count($reachable) !== $before);
        $issues = [];
        $previous = $root;
        foreach ($edges as $number => [$s, $e]) {
            $hint = $previous ? ' Previous pair ends at '.$previous['waypoint_reference'].'. Check the PDF before correcting the start.' : '';
            if ($s && $e && $key($s) && $key($s) === $key($e)) {
                $issues[] = ['code' => 'entry_same_waypoint', 'message' => 'Pair '.$number.': S and E are the same waypoint '.$s['waypoint_reference'].', creating a zero-length span.'.$hint, 'row' => $s];
            }
            if ($reachable && $s && $key($s) && ! isset($reachable[$key($s)])) {
                $issues[] = ['code' => 'entry_disconnected_start', 'message' => 'Pair '.$number.': starting waypoint '.$s['waypoint_reference'].' has no entered connection to the transformer.'.$hint.' A branch may start at any point already connected to the transformer.', 'row' => $s];
            }
            $previous = $e;
        }

        return $issues;
    }
}
