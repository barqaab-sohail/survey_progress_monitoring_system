<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Configuration;
use App\Models\Mdb\Template;

class EntryLookups
{
    private const LEGACY_CONDUCTOR_CODES = ['ABC 50mm2' => 'ABC 50 MM2', 'ABC 95mm2' => 'ABC 95 MM2'];

    public const SINGLE_PHASE_CONSUMERS = ['rs', 'sc', 'st'];

    public const CONSUMERS = ['rs' => '1-phase residential', 'rl' => '3-phase residential', 'sc' => '1-phase commercial',
        'lc' => 'Commercial (LC; confirm classification)', 'si' => '3-phase industrial', 'li' => '3-phase industrial',
        'pb' => 'Public buildings', 'ag' => 'Agriculture', 'st' => 'Street lights', 'pv' => 'PV solar'];

    public function options(?int $projectId = null): array
    {
        // Printed legend and requested warehouse IDs. Availability is not engineering approval.
        $conductors = ['A' => 'A — Ant', 'W' => 'W — Wasp', 'GN' => 'GN — Gnat', '2/0 AWG' => '2/0 AWG',
            'PVC 7/0.052' => 'PVC 7/0.052', 'PVC 19/0.052' => 'PVC 19/0.052', 'PVC 19/0.083' => 'PVC 19/0.083',
            'PVC 0.083' => 'PVC 0.083', 'PVC 37/0.083' => 'PVC 37/0.083 — as written in form',
            'ABC 50 MM2' => 'ABC 50 MM2', 'ABC 95 MM2' => 'ABC 95 MM2',
            'DOG' => 'DOG', 'GOPHER' => 'GOPHER', 'GSL' => 'GSL', 'OSPREY' => 'OSPREY',
            'PANTHER' => 'PANTHER', 'RABBIT' => 'RABBIT', 'RACCOON' => 'RACCOON',
            'INS ANT' => 'INS ANT', 'INS GNAT' => 'INS GNAT', 'INS WASP' => 'INS WASP', 'INS 7/0.052' => 'INS 7/0.052',
            'ABC' => 'ABC', 'ABC 10 MM2' => 'ABC 10 MM2', 'ABC 16 MM2' => 'ABC 16 MM2',
            'ABC 25 MM2' => 'ABC 25 MM2', 'ABC 35 MM2' => 'ABC 35 MM2', 'ABC 70 MM2' => 'ABC 70 MM2',
            '4/0 AWG' => '4/0 AWG', '100 MM2' => '100 MM2', '1000 MCM' => '1000 MCM'];
        $templateId = $projectId ? (Configuration::where('project_id', $projectId)->value('settings')['template_id'] ?? null) : null;
        $templates = Template::where('active', true)->whereNotNull('approved_by')->whereNotNull('approved_at');
        $metadata = ($templateId ? $templates->whereKey($templateId)->first() : $templates->latest('id')->first())?->metadata ?? [];
        foreach ($metadata['equipment_references']['conductors'] ?? [] as $code) {
            $code = self::LEGACY_CONDUCTOR_CODES[$code] ?? $code;
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
        // Preserve historic observations while resolving their explicitly renamed warehouse IDs.
        $code = self::LEGACY_CONDUCTOR_CODES[$code ?? ''] ?? $code;

        return match ($code) {
            'A' => 'ANT', 'W' => 'WASP', 'GN' => 'GNAT', default => $code
        };
    }
}
