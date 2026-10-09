<?php

namespace App\Support;

class IntersectionFlag
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (strtolower(trim($value))) {
            '', '0', 'false', 'no', 'off', '-' => false,
            '1', 'true', 'yes', 'on', 'int', 'intersection', '✓', '✔', 'x', '×', '+' => true,
            default => $value,
        };
    }
}
