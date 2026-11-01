<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** One source-derived plan shared by Node and InstSection generation. */
class ConsumerPhaseConnections
{
    public function build(array $section, array $entries, array $pvRecords, string $label): array
    {
        $rows = array_values(array_filter($entries, fn ($row) => isset($row['section_id'])
            ? $row['section_id'] == $section['id']
            : ($section['original_entry']['entry_pair_number'] ?? null) == $row['pair_number']));
        $phases = $section['phases'] ?? [];
        if (is_string($phases)) {
            $phases = str_split($phases);
        }
        $this->check(is_array($phases), $label.': PDF phase availability is ambiguous.');
        $available = array_values(array_intersect(['R', 'Y', 'B'], $phases));
        $consumers = [];
        $physicalCount = 0;
        foreach ($section['consumers'] ?? [] as $consumer) {
            $category = strtolower((string) ($consumer['category'] ?? ''));
            $count = $consumer['count'] ?? null;
            $observed = null;
            foreach ($rows as $row) {
                if (($row['consumers'][$category] ?? null) !== null) {
                    $observed = ($observed ?? 0) + $this->count($row['consumers'][$category], $label.' '.$category);
                }
            }
            $this->check($observed === null || ($count !== null && $observed === $this->count($count, $label.' '.$category)),
                $label.': PDF '.$category.' count differs from its mapped section total; reverify the record.');
            if ($count === null) {
                $recorded = (new RecordedConsumerPhases)->fromRows($rows, $category);
                $this->check($recorded === null || array_sum($recorded) === 0, $label.': recorded phase counts have no verified section total.');

                continue;
            }
            $count = $this->count($count, $label.' consumer');
            if ($count === 0) {
                continue;
            }
            $this->check(in_array($category, EngineeringConfiguration::CATEGORIES, true), $label.': unknown PDF consumer category '.$category.'.');
            $consumers[] = ['category' => $category, 'count' => $count];
            $physicalCount += $count;
        }
        $pvCount = $this->pvCount($section, $rows, $pvRecords, $label);
        $this->check(($physicalCount === 0 && $pvCount === 0) || ($available !== [] && in_array('N', $phases, true)),
            $label.': consumer/PV connections require confirmed phase and neutral availability.');
        $plan = [];
        $physical = $cursor = 0;
        // Retain every pre-existing physical consumer suffix. Extra phase/PV suffixes follow them.
        $extra = $physicalCount + 1;
        foreach ($consumers as $consumer) {
            $category = $consumer['category'];
            if (in_array($category, EntryLookups::SINGLE_PHASE_CONSUMERS, true)) {
                $recorded = (new RecordedConsumerPhases)->fromRows($rows, $category);
                $allocated = [];
                // When all three phase-neutral combinations are available, distribute
                // single-phase customer sections across them in a stable repeating order.
                // Partial phase availability continues to honor the recorded allocation.
                if ($recorded !== null && $available !== ['R', 'Y', 'B']) {
                    $this->check(array_sum($recorded) === $consumer['count'], $label.': recorded phase counts differ from the '.$category.' total.');
                    foreach ($recorded as $phase => $count) {
                        $this->check($count === 0 || in_array($phase, $available, true), $label.': recorded '.$phase.' customers have no corresponding PDF conductor.');
                        array_push($allocated, ...array_fill(0, $count, $phase));
                    }
                } else {
                    for ($i = 0; $i < $consumer['count']; $i++) {
                        $allocated[] = $available[$cursor++ % count($available)];
                    }
                }
                foreach ($allocated as $phase) {
                    $plan[] = ['suffix' => ++$physical, 'phase' => $phase, 'kind' => 'consumer', 'category' => $category];
                }
            } else {
                $this->threePhases($available, $label.' '.$category.' consumer');
                for ($i = 0; $i < $consumer['count']; $i++) {
                    $plan[] = ['suffix' => ++$physical, 'phase' => 'R', 'kind' => 'consumer', 'category' => $category];
                    foreach (['Y', 'B'] as $phase) {
                        $plan[] = ['suffix' => $extra++, 'phase' => $phase, 'kind' => 'consumer', 'category' => $category];
                    }
                }
            }
        }
        if ($pvCount > 0) {
            // A confirmed Y/neutral-only PV keeps three distinct connections, all on YN.
            $pvPhases = ['R', 'Y', 'B'];
            if ($available === ['Y']) {
                $pvPhases = ['Y', 'Y', 'Y'];
            } else {
                $this->threePhases($available, $label.' PV installation');
            }
            for ($i = 0; $i < $pvCount; $i++) {
                foreach ($pvPhases as $phase) {
                    $plan[] = ['suffix' => $extra++, 'phase' => $phase, 'kind' => 'pv', 'category' => 'pv'];
                }
            }
        }
        usort($plan, fn ($a, $b) => $a['suffix'] <=> $b['suffix']);

        return $plan;
    }

    private function pvCount(array $section, array $rows, array $records, string $label): int
    {
        $explicit = null;
        $details = 0;
        foreach ($rows as $row) {
            if (($row['consumers']['pv'] ?? null) !== null) {
                $explicit = ($explicit ?? 0) + $this->count($row['consumers']['pv'], $label.' PV');
            }
            $details += count($row['pv_details'] ?? []);
        }
        $linked = count(array_filter($records, fn ($pv) => ($pv['section_id'] ?? null) == $section['id']));
        $sectionRecords = count($section['pv_records'] ?? []);
        $observations = array_values(array_filter([$details, $linked, $sectionRecords], fn ($n) => $n > 0));
        if ($explicit !== null) {
            $observations[] = $explicit;
        }
        $this->check(count(array_unique($observations)) <= 1, $label.': PV count and installation records disagree. Verify the PDF before export.');

        return $observations[0] ?? 0;
    }

    private function threePhases(array $available, string $label): void
    {
        $this->check($available === ['R', 'Y', 'B'], $label.': three-phase expansion requires R, Y and B conductors in the PDF; unavailable phases were not invented.');
    }

    private function count(mixed $value, string $label): int
    {
        $this->check(is_numeric($value) && is_finite((float) $value) && $value >= 0 && floor((float) $value) == $value && $value <= PHP_INT_MAX,
            $label.': a recorded nonnegative integer count is required.');

        return (int) $value;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }
}
