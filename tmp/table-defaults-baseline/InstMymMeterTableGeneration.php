<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Generate base and forecast meter rows from the literal InstMymMeter.xlsx pattern. */
class InstMymMeterTableGeneration
{
    private const REFERENCE_VALUES = [
        'Phase1Value' => 10.3,
        'Phase2Value' => 10.3,
        'Phase3Value' => 10.3,
        'Phase1Value2' => 85,
        'Phase2Value2' => 85,
        'Phase3Value2' => 85,
        'CalibSAIFI' => 1,
        'CalibSAIDI_Hr' => 4,
        'CalibMAIFI' => 3,
        'ContinuousCurrentRating' => 0,
        'EmergencyCurrentRating' => 0,
        'MeterIsOpen' => 0,
        'UseDemandAsLoad' => 0,
    ];

    public function apply(array $transformers, array $sections): array
    {
        $sectionsById = array_column($sections, null, 'SectionId');
        $rows = $used = [];
        foreach ($transformers as $transformer) {
            $id = $transformer['SectionId'] ?? null;
            $this->check(is_string($id) && isset($sectionsById[$id])
                && ($sectionsById[$id]['SectionId'] ?? null) === $id,
                'InstMymMeter: transformer SectionId does not match an InstSection.SectionId.');
            $description = trim((string) ($sectionsById[$id]['Description'] ?? ''));
            $this->check($description === '' || strcasecmp($description, 'Transformer') === 0,
                'InstMymMeter: referenced InstSection row is not identified as a transformer.');
            $this->check(! isset($used[$id]), 'InstMymMeter: duplicate transformer section reference.');
            $used[$id] = true;

            foreach ([0, 1] as $year) {
                $rows[] = ['SectionId' => $id, ...self::REFERENCE_VALUES, 'YearNumber' => $year];
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
