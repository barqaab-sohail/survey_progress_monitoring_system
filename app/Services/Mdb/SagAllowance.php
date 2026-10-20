<?php

namespace App\Services\Mdb;

use InvalidArgumentException;

/** An explicit Admin-entered extra-length percentage, separate from physical sag modelling. */
class SagAllowance
{
    public static function percent(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 100) {
            throw new InvalidArgumentException('Sag allowance must be between 0 and 100 percent, or left blank.');
        }

        return (float) $value;
    }

    public static function apply(?float $spanLength, mixed $percent): array
    {
        $percent = self::percent($percent);
        $extra = $spanLength === null ? null : $spanLength * ($percent ?? 0) / 100;

        return ['span_length_m' => $spanLength, 'sag_allowance_percent' => $percent,
            'sag_addition_m' => $extra, 'length_m' => $spanLength === null ? null : $spanLength + $extra];
    }
}
