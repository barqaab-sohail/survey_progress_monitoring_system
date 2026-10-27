<?php

namespace App\Services\Mdb;

/** Structural completeness for model review; never claims native SynerGEE acceptance. */
class ModelReadiness
{
    public function inspect(array $payload): array
    {
        $tables = $payload['tables'];
        $checks = [];
        $add = static function (string $label, array $rows, callable $valid) use (&$checks): void {
            $complete = count(array_filter($rows, $valid));
            $checks[] = ['label' => $label, 'complete' => $complete, 'total' => count($rows), 'missing' => count($rows) - $complete];
        };
        $number = static fn ($v) => is_numeric($v) && is_finite((float) $v);
        $add('Map coordinates', $tables['Node'], fn ($r) => $number($r['X'] ?? null) && $number($r['Y'] ?? null));
        $add('Section lengths', $tables['InstSection'], fn ($r) => $number($r['SectionLength_MUL'] ?? null) && $r['SectionLength_MUL'] >= 0);
        $add('Transformer equipment type', $tables['InstPrimaryTransformers'], fn ($r) => trim((string) ($r['TransformerType'] ?? '')) !== '');
        $add('Line configuration ID', $tables['InstSection'], fn ($r) => trim((string) ($r['ConfigurationId'] ?? '')) !== '');
        $add('Section phases', $tables['InstSection'], fn ($r) => preg_match('/^[R ][Y ][B ][N ]?$/D', $r['SectionPhases'] ?? '') === 1 && preg_match('/[RYB]/', $r['SectionPhases'] ?? '') === 1);
        $sections = array_column($tables['InstSection'], null, 'SectionId');
        $add('Load demand and customer allocation', $tables['Loads'], function ($row) use ($number, $sections) {
            $phases = $sections[$row['SectionId']]['SectionPhases'] ?? '';
            if (! preg_match('/[RYB]/', $phases)) {
                return false;
            }
            foreach (['R', 'Y', 'B'] as $i => $phase) {
                if (! str_contains($phases, $phase)) {
                    continue;
                }
                // Category rates provide apparent demand directly. kW and kvar
                // stay unset because this method does not assume a power factor.
                foreach (['Kva', 'Customers'] as $field) {
                    if (! $number($row['Phase'.($i + 1).$field] ?? null)) {
                        return false;
                    }
                }
            }

            return true;
        });

        if (! empty($payload['pv_records'])) {
            $generators = array_column($tables['InstDGens'] ?? [], null, 'SectionId');
            $sectionIds = array_column($payload['provenance'] ?? [], 'section_id', 'survey_section_id');
            $years = $tables['InstMymDGens'] ?? [];
            $add('PV generation mapping', $payload['pv_records'], function ($pv) use ($generators, $sectionIds, $years, $sections, $number) {
                $id = $sectionIds[$pv['section_id'] ?? ''] ?? null;
                if ($id === null || ! isset($generators[$id])) {
                    return false;
                }
                $base = collect($years)->first(fn ($row) => $row['SectionId'] === $id && ($row['YearNumber'] ?? null) === 0);
                if (! $base || ! preg_match('/[RYB]/', $sections[$id]['SectionPhases'] ?? '')) {
                    return false;
                }
                foreach (['R', 'Y', 'B'] as $i => $phase) {
                    if (str_contains($sections[$id]['SectionPhases'], $phase) && ! $number($base['Phase'.($i + 1).'Kw'] ?? null)) {
                        return false;
                    }
                }

                return true;
            });
        }

        return ['complete' => array_sum(array_column($checks, 'missing')) === 0, 'checks' => $checks,
            'acceptance' => 'Native SynerGEE equipment-library checks and load-flow acceptance remain pending.'];
    }
}
