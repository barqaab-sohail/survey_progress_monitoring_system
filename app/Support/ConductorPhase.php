<?php

namespace App\Support;

class ConductorPhase
{
    public static function fromRow(array $row): string
    {
        $phase = '';
        foreach (['r', 'y', 'b'] as $key) {
            $value = $row['conductor_'.$key] ?? null;
            if (is_string($value) && ! in_array(strtolower(trim($value)), ['', '+', '-', '–', '—', 'x', '×'], true)) {
                $phase .= strtoupper($key);
            }
        }

        return $phase;
    }
}
