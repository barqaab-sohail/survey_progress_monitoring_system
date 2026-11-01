<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Build year-indexed load records from this export's Loads rows and final sections. */
class InstMymLoadsGeneration
{
    private const PHASE_FIELDS = [
        'Phase1Customers', 'Phase2Customers', 'Phase3Customers',
        'Phase1Kw', 'Phase2Kw', 'Phase3Kw',
        'Phase1Kvar', 'Phase2Kvar', 'Phase3Kvar',
        'Phase1Kva', 'Phase2Kva', 'Phase3Kva',
        'Phase1Kwh', 'Phase2Kwh', 'Phase3Kwh',
    ];

    public function apply(array $loads, array $sections): array
    {
        $sectionsById = array_column($sections, null, 'SectionId');
        $loadsByKey = [];
        foreach ($loads as $load) {
            $id = $load['SectionId'] ?? null;
            $this->check(is_string($id) && isset($sectionsById[$id])
                && ($sectionsById[$id]['SectionId'] ?? null) === $id,
                'InstMymLoads: Loads.SectionId does not match an InstSection.SectionId.');
            $spot = (int) ($load['IsSpotLoad'] ?? 0);
            $capacityFactor = $this->zeroIfBlank(array_key_exists('CapacityFactor', $load) ? $load['CapacityFactor'] : 1.0);
            $record = ['SectionId' => $id, 'IsSpotLoad' => $spot];
            foreach (self::PHASE_FIELDS as $field) {
                $value = $this->zeroIfBlank($load[$field] ?? null);
                if (str_ends_with($field, 'Customers')) {
                    $this->check($value === null || (is_numeric($value) && is_finite((float) $value)
                        && $value >= 0 && floor((float) $value) === (float) $value),
                        'InstMymLoads: customer counts must be nonnegative whole numbers.');
                }
                $record[$field] = $value;
            }
            $record['CapacityFactor'] = $capacityFactor;
            $key = json_encode([$id], JSON_THROW_ON_ERROR);
            $this->check(! isset($loadsByKey[$key]) || $loadsByKey[$key] === $record,
                'InstMymLoads: conflicting source records for the same SectionId.');
            // Repeated identical input must not double customer counts or demand.
            $loadsByKey[$key] = $record;
        }

        $rows = $keys = [];
        foreach ($loadsByKey as $load) {
            $id = $load['SectionId'];
            $section = $sectionsById[$id];
            // In the reference pattern pole aggregate records begin at year 1.
            // A load on an individual phase-specific connection exists in years 0 and 1.
            $connectionLoad = ($section['Description'] ?? null) === 'Secondary Pole'
                && str_contains((string) ($section['ToNodeId'] ?? ''), '.');
            $spot = $load['IsSpotLoad'];
            $capacityFactor = $load['CapacityFactor'];
            foreach ([0, 1] as $year) {
                $key = json_encode([$id, $year], JSON_THROW_ON_ERROR);
                $this->check(! isset($keys[$key]), 'InstMymLoads: duplicate SectionId / YearNumber key.');
                $keys[$key] = true;
                $row = ['SectionId' => $id, 'IsSpotLoad' => $spot];
                foreach (self::PHASE_FIELDS as $field) {
                    $value = $load[$field] ?? null;
                    $row[$field] = $year === 0 && ! $connectionLoad && $value !== null ? 0.0 : $value;
                }
                $row['CapacityFactor'] = $capacityFactor;
                $row['YearNumber'] = $year;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }

    private function zeroIfBlank(mixed $value): mixed
    {
        return $value === null || (is_string($value) && trim($value) === '') ? 0 : $value;
    }
}
