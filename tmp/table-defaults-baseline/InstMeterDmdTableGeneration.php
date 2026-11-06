<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Generate the literal monthly/day-type load profile from the approved meter demand pattern. */
class InstMeterDmdTableGeneration
{
    private const SEASONS = [
        'April', 'August', 'December', 'February', 'January', 'July',
        'June', 'March', 'May', 'November', 'October', 'September',
    ];

    private const DAY_TYPES = ['Minday', 'Peakday', 'Weekday', 'Weekend'];

    public function apply(array $meterPoints, array $sections): array
    {
        $sectionIds = array_column($sections, null, 'SectionId');
        $used = [];
        $rows = [];

        foreach ($meterPoints as $meterPoint) {
            $id = $meterPoint['SectionId'] ?? null;
            $this->check(is_string($id) && $id !== '' && isset($sectionIds[$id]),
                'InstMeterDmd: SectionId must match an existing InstMeterPoints/InstSection record.');
            $this->check(($sectionIds[$id]['SectionId'] ?? null) === $id,
                'InstMeterDmd: SectionId must be preserved exactly as stored.');
            $description = trim((string) ($sectionIds[$id]['Description'] ?? ''));
            $this->check($description === '' || strcasecmp($description, 'Transformer') === 0,
                'InstMeterDmd: meter point must reference the transformer section.');
            $this->check(! isset($used[$id]), 'InstMeterDmd: duplicate meter-point SectionId.');
            $used[$id] = true;

            foreach (self::SEASONS as $season) {
                foreach (self::DAY_TYPES as $dayType) {
                    $row = ['SectionId' => $id, 'Season' => $season, 'DayType' => $dayType];
                    foreach (['PctKwLoad', 'PctKvarLoad'] as $group) {
                        for ($hour = 1; $hour <= 24; $hour++) {
                            $row[sprintf('%s_Hour%02d', $group, $hour)] = 100;
                        }
                    }
                    $row['PctPf'] = 95;
                    foreach (['PctKwDmd', 'PctKvarDmd'] as $group) {
                        for ($hour = 1; $hour <= 24; $hour++) {
                            $row[sprintf('%s_Hour%02d', $group, $hour)] = 100;
                        }
                    }
                    $rows[] = $row;
                }
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
}
