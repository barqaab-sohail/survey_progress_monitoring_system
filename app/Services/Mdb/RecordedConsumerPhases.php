<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Read phase observations without changing the source rows or their totals. */
class RecordedConsumerPhases
{
    public function fromRows(array $rows, string $category): ?array
    {
        if (! in_array($category, EntryLookups::SINGLE_PHASE_CONSUMERS, true)) {
            return null;
        }
        $counts = array_fill_keys(['R', 'Y', 'B'], 0);
        $found = false;
        foreach ($rows as $row) {
            $entered = $row['consumer_phases'][$category] ?? $row['original_entry']['consumer_phases'][$category] ?? [];
            $values = [];
            foreach (['R', 'Y', 'B'] as $phase) {
                $value = $entered[$phase] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < 0) {
                    throw ValidationException::withMessages(['engineering' => strtoupper($category).' recorded phase counts must be nonnegative integers.']);
                }
                $values[$phase] = (int) $value;
            }
            if ($values === []) {
                continue;
            }
            $total = $row['consumers'][$category] ?? null;
            if ($total !== null && (int) $total !== array_sum($values)) {
                throw ValidationException::withMessages(['engineering' => strtoupper($category).' recorded phase counts differ from the survey row total. Correct and reverify the row.']);
            }
            $found = true;
            foreach ($values as $phase => $count) {
                $counts[$phase] += $count;
            }
        }

        return $found ? $counts : null;
    }
}
