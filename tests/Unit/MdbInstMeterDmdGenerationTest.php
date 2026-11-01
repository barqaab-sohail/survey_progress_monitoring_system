<?php

namespace Tests\Unit;

use App\Services\Mdb\InstMeterDmdTableGeneration;
use Tests\TestCase;

class MdbInstMeterDmdGenerationTest extends TestCase
{
    public function test_generates_reference_profile_once_per_exact_meter_point_section_id(): void
    {
        $meterPoints = [
            ['SectionId' => '00040701031222104.1'],
            ['SectionId' => '00040701031222104.2'],
        ];
        $sections = [
            ['SectionId' => '00040701031222104.1', 'Description' => 'Transformer'],
            ['SectionId' => '00040701031222104.2', 'Description' => 'Transformer'],
        ];

        $rows = (new InstMeterDmdTableGeneration)->apply($meterPoints, $sections);

        $this->assertCount(96, $rows);
        $this->assertSame(['00040701031222104.1', '00040701031222104.2'], array_values(array_unique(array_column($rows, 'SectionId'))));
        $this->assertCount(48, array_filter($rows, fn ($row) => $row['SectionId'] === '00040701031222104.1'));
        $this->assertSame(['April', 'Minday'], [$rows[0]['Season'], $rows[0]['DayType']]);
        $this->assertSame(['April', 'Weekend'], [$rows[3]['Season'], $rows[3]['DayType']]);
        $this->assertSame(['August', 'Minday'], [$rows[4]['Season'], $rows[4]['DayType']]);
        $this->assertSame(95, $rows[0]['PctPf']);
        foreach (array_keys($rows[0]) as $field) {
            if (str_contains($field, '_Hour')) {
                $this->assertSame(100, $rows[0][$field], $field);
            }
        }
        $this->assertSame(array_keys($rows[0]), array_keys($rows[47]));
        $this->assertSame(['00040701031222104.2', 'September', 'Weekend'], [$rows[48]['SectionId'], $rows[95]['Season'], $rows[95]['DayType']]);
    }
}
