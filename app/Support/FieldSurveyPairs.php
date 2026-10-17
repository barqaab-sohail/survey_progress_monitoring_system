<?php

namespace App\Support;

class FieldSurveyPairs
{
    /** Legacy rows pair by adjacent S/E. Explicit IDs never fall back to adjacency. */
    public static function errors(array $rows): array
    {
        $rows = array_values($rows);
        $groups = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['pair_id'] ?? null;
            $legacyEnd = ! $id && $index > 0 && ($row['se'] ?? null) === 'E'
                && ($rows[$index - 1]['se'] ?? null) === 'S' && empty($rows[$index - 1]['pair_id']);
            $key = is_string($id) && $id !== '' ? 'id:'.$id : 'legacy:'.($legacyEnd ? $index - 1 : $index);
            $groups[$key][] = $index;
        }
        $errors = [];
        if ($rows === []) {
            $errors['rows'] = 'Complete at least one S/E pair before submission.';
        }
        foreach ($groups as $indices) {
            $roles = array_map(fn ($i) => $rows[$i]['se'] ?? null, $indices);
            if (count($indices) !== 2 || count(array_filter($roles, fn ($role) => $role === 'S')) !== 1
                || count(array_filter($roles, fn ($role) => $role === 'E')) !== 1) {
                $errors['rows'] = 'Every pair needs exactly one S row and one E row.';
                foreach ($indices as $index) {
                    $errors['rows.'.$index.'.pair'] = 'Incomplete or duplicate S/E pair. Complete the matching row.';
                }
            }
        }

        return $errors;
    }

    public static function hasConductor(array $row): bool
    {
        foreach (['r', 'y', 'b', 'neutral'] as $key) {
            $value = $row['conductor_'.$key] ?? null;
            if (is_string($value) && ! in_array(strtolower(trim($value)), ['', '__none__', '+', '-', '–', '—', 'x', '×', 'other'], true)) {
                return true;
            }
        }

        return false;
    }
}
