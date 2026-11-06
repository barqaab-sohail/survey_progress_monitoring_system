<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Generate base and forecast meter rows from the literal InstMymMeter.xlsx pattern. */
class InstMymMeterTableGeneration
{
    public function apply(array $transformers, array $sections, ?array $profile = null): array
    {
        $profile ??= (new MdbTableDefaults)->table('InstMymMeter');
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

            foreach (range($profile['first_year'], $profile['last_year']) as $year) {
                $rows[] = ['SectionId' => $id, ...$profile['values'], 'YearNumber' => $year];
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
