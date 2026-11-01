<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Workbook calculations shared by section generation; never changes survey observations. */
class SectionGenerationRules
{
    public function identifier(string $feederCode, string $group, string $date, string $waypoint, ?int $customer = null): string
    {
        $this->check(preg_match('/^\d{1,6}$/D', $feederCode) === 1, 'SectionId requires a numeric feeder code of at most six digits.');
        $feederCode = str_pad($feederCode, 6, '0', STR_PAD_LEFT);
        $this->check(preg_match('/^\d{2}$/D', $group) === 1 && preg_match('/^\d{3}$/D', $waypoint) === 1,
            'SectionId requires two group digits and three waypoint digits, including zeros.');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $this->check($parsed && $parsed->format('Y-m-d') === $date, 'SectionId requires a verified calendar date.');
        $this->check($customer === null || $customer > 0, 'Consumer section suffix must be a positive sequence number.');
        // Reference IDs and workbook records concatenate fields; the hyphens in the note show grouping only.
        $id = $feederCode.$group.$parsed->format('dmy').$waypoint.($customer === null ? '' : '.'.$customer);
        $this->check(strlen($id) <= 32, 'SectionId exceeds the existing MDB Text(32) limit; no identifier was truncated.');

        return $id;
    }

    public function consumerCableLength(array $pole, string $record): float
    {
        $height = $pole['pole_height'] ?? null;
        $unit = $pole['pole_height_unit'] ?? null;
        $this->check(is_numeric($height) && is_finite((float) $height) && $height > 0 && in_array($unit, ['ft', 'm'], true),
            $record.': the consumer cable formula requires the recorded pole height and its explicit ft/m unit.');
        $metres = $unit === 'ft' ? (float) $height * 0.3048 : (float) $height;
        $this->check($metres >= 4 * 0.3048, $record.': pole height is below the formula\'s four-foot clearance. Verify the source PDF.');

        return round(($metres - 4 * 0.3048) / 0.667, 2, PHP_ROUND_HALF_UP);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }
}
