<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Generate the literal monthly/day-type load profile from the approved meter demand pattern. */
class InstMeterDmdTableGeneration
{
    public function apply(array $meterPoints, array $sections, ?array $profile = null): array
    {
        $profile ??= (new MdbTableDefaults)->table('InstMeterDmd');
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

            foreach ($profile['seasons'] as $season) {
                foreach ($profile['day_types'] as $dayType) {
                    $row = ['SectionId' => $id, 'Season' => $season, 'DayType' => $dayType];
                    foreach (['PctKwLoad', 'PctKvarLoad'] as $group) {
                        for ($hour = 1; $hour <= 24; $hour++) {
                            $row[sprintf('%s_Hour%02d', $group, $hour)] = $profile['hourly'][$group][sprintf('%02d', $hour)] ?? $profile['values'][$group];
                        }
                    }
                    $row['PctPf'] = $profile['values']['PctPf'];
                    foreach (['PctKwDmd', 'PctKvarDmd'] as $group) {
                        for ($hour = 1; $hour <= 24; $hour++) {
                            $row[sprintf('%s_Hour%02d', $group, $hour)] = $profile['hourly'][$group][sprintf('%02d', $hour)] ?? $profile['values'][$group];
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
