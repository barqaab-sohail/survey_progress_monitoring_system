<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Literal InstMymSwDevPos.xlsx values, linked to the final transformer section. */
class InstMymSwDevPosTableGeneration
{
    public function apply(array $transformers, array $sections, ?array $profile = null): array
    {
        $profile ??= (new MdbTableDefaults)->table('InstMymSwDevPos');
        $sectionsById = array_column($sections, null, 'SectionId');
        $rows = $used = [];
        foreach ($transformers as $transformer) {
            $id = $transformer['SectionId'] ?? null;
            $this->check(is_string($id) && $id !== '' && isset($sectionsById[$id])
                && ($sectionsById[$id]['SectionId'] ?? null) === $id,
                'InstMymSwDevPos: transformer SectionId must match an existing InstSection.SectionId exactly.');
            $description = trim((string) ($sectionsById[$id]['Description'] ?? ''));
            $this->check($description === '' || strcasecmp($description, 'Transformer') === 0,
                'InstMymSwDevPos: SectionId must reference the transformer section.');
            $this->check(! isset($used[$id]), 'InstMymSwDevPos: duplicate transformer SectionId.');
            $used[$id] = true;
            foreach (range($profile['first_year'], $profile['last_year']) as $year) {
                $rows[] = ['SectionId' => $id, 'DeviceCode' => $profile['values']['DeviceCode'], 'YearNumber' => $year, 'IsOpen' => $profile['values']['IsOpen']];
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
