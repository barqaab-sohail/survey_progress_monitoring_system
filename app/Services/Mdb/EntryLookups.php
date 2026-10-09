<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Template;

class EntryLookups
{
    public const CONSUMERS = ['rs' => '1-phase residential', 'rl' => '3-phase residential', 'sc' => '1-phase commercial',
        'lc' => 'Commercial (LC; confirm classification)', 'si' => '1-phase industrial', 'li' => '3-phase industrial',
        'pb' => 'Public buildings', 'ag' => 'Agriculture', 'st' => 'Street lights', 'pv' => 'PV solar'];

    public function options(): array
    {
        // Printed legend values. They are observations, not approval of an equipment library.
        $conductors = ['A' => 'A — Ant', 'W' => 'W — Wasp', 'GN' => 'GN — Gnat', '2/0 AWG' => '2/0 AWG',
            'PVC 7/0.052' => 'PVC 7/0.052', 'PVC 19/0.052' => 'PVC 19/0.052', 'PVC 19/0.083' => 'PVC 19/0.083',
            'PVC 0.083' => 'PVC 0.083', 'PVC 37/0.083' => 'PVC 37/0.083 — as written in form', 'ABC 50mm2' => 'ABC cable — 50 mm²', 'ABC 95mm2' => 'ABC cable — 95 mm²'];
        $metadata = Template::where('active', true)->whereNotNull('approved_by')->whereNotNull('approved_at')->latest('id')->first()?->metadata ?? [];
        foreach ($metadata['equipment_references']['conductors'] ?? [] as $code) {
            $conductors[$code] ??= $code.' — approved library';
        }

        return ['conductors' => $conductors,
            'equipment_types' => array_replace(['TR' => 'TR — form code (description unconfirmed)', 'SP' => 'SP — form code (description unconfirmed)', 'Ang' => 'Ang — Angle', 'WB' => 'WB — Wall bracket'], $this->namedOptions($metadata['entry_lookups']['equipment_types'] ?? [])),
            'pole_classes' => array_replace(['S' => 'S — Steel structure', 'PCO' => 'PCO — PC ordinary', 'PCS' => 'PCS — PC spun',
                'RS' => 'RS — Rail steel', 'TS' => 'TS — Tubular steel', 'WB' => 'WB — Wall bracket'], $this->namedOptions($metadata['entry_lookups']['pole_classes'] ?? [])),
            'consumers' => self::CONSUMERS,
            'pole_height_unit' => config('mdb_workflow.entry.pole_height_unit'),
        ];
    }

    private function namedOptions(array $options): array
    {
        return array_filter($options, fn ($label, $code) => is_string($code) && is_string($label) && $code !== '' && $label !== '', ARRAY_FILTER_USE_BOTH);
    }

    /** null = not entered; empty string = explicitly absent. Neutral is separate. */
    public static function phase(array $conductors): array
    {
        $active = [];
        $complete = true;
        foreach (['R', 'Y', 'B'] as $phase) {
            $value = $conductors[$phase] ?? null;
            $complete = $complete && $value !== null;
            if (is_string($value) && $value !== '') {
                $active[] = $phase;
            }
        }

        return ['display' => implode('', $active), 'phases' => $active, 'complete' => $complete];
    }

    public static function libraryCode(?string $code): ?string
    {
        // Reuse the established application's explicit survey-code aliases.
        return match ($code) {
            'A' => 'ANT', 'W' => 'WASP', 'GN' => 'GNAT', default => $code
        };
    }
}
