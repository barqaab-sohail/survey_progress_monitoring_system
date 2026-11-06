<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Generate meter rows from the literal InstMeterPoints.xlsx reference row. */
class InstMeterPointsTableGeneration
{
    public function apply(array $transformers, array $sections, ?array $profile = null): array
    {
        $profile ??= (new MdbTableDefaults)->table('InstMeterPoints');
        $sectionsById = array_column($sections, null, 'SectionId');
        $rows = $used = [];
        foreach ($transformers as $transformer) {
            $id = $transformer['SectionId'] ?? null;
            $this->check(is_string($id) && isset($sectionsById[$id])
                && ($sectionsById[$id]['SectionId'] ?? null) === $id,
                'InstMeterPoints: transformer SectionId does not match an InstSection.SectionId.');
            $description = trim((string) ($sectionsById[$id]['Description'] ?? ''));
            $this->check($description === '' || strcasecmp($description, 'Transformer') === 0,
                'InstMeterPoints: referenced InstSection row is not identified as a transformer.');
            $this->check(! isset($used[$id]), 'InstMeterPoints: duplicate transformer section reference.');
            $used[$id] = true;
            $rows[] = ['SectionId' => $id, ...$profile['values']];
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
