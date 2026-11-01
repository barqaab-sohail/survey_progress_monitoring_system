<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Generate meter rows from the literal InstMeterPoints.xlsx reference row. */
class InstMeterPointsTableGeneration
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
        'Description' => '132 KVA Abotabad',
        'Enabled' => 1,
        'EnableReliCalibration' => 0,
        'NearFromNode' => 1,
        'Type' => 'I',
        'OverriddenUpstream' => 1,
        'EnergizeYear' => 0,
        'RetireYear' => 100,
        'Note_' => null,
        'UseGrowthCurve' => 0,
        'GrowthCurveId' => null,
        'UseDefDistCustZone' => 0,
        'DefDistCustZone' => 'Unknown',
        'UseDefSpotCustZone' => 0,
        'DefSpotCustZone' => 'Unknown',
        'IsColorBy' => 0,
        'RGBColor' => '125,125',
        'LocationLink' => '*',
        'AMSLink' => null,
        'LockFedLoad' => 0,
    ];

    public function apply(array $transformers, array $sections): array
    {
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
            $rows[] = ['SectionId' => $id, ...self::REFERENCE_VALUES];
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
