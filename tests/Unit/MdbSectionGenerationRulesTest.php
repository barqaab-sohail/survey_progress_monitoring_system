<?php

namespace Tests\Unit;

use App\Services\Mdb\SectionGenerationRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbSectionGenerationRulesTest extends TestCase
{
    public function test_section_identifiers_preserve_code_date_waypoint_zeros_and_decimal_suffixes(): void
    {
        $rules = new SectionGenerationRules;
        $this->assertSame('00040701131222104', $rules->identifier('000407', '01', '2022-12-13', '104'));
        $this->assertSame('00040711131222104', $rules->identifier('407', '11', '2022-12-13', '104'));
        $this->assertSame('00040701081026001', $rules->identifier('000407', '01', '2026-10-08', '001'));
        $this->assertSame('00040701081026001.10', $rules->identifier('000407', '01', '2026-10-08', '001', 10));
        $this->assertNotSame($rules->identifier('000407', '01', '2026-10-08', '001', 1), $rules->identifier('000407', '01', '2026-10-08', '001', 10));
    }

    public function test_consumer_cable_lengths_match_both_workbook_examples_and_recorded_units(): void
    {
        $rules = new SectionGenerationRules;
        $this->assertSame(14.62, $rules->consumerCableLength(['pole_height' => 36, 'pole_height_unit' => 'ft'], 'Pole 001'));
        $this->assertSame(12.34, $rules->consumerCableLength(['pole_height' => 31, 'pole_height_unit' => 'ft'], 'Pole 002'));
        $this->assertSame(14.62, $rules->consumerCableLength(['pole_height' => 10.9728, 'pole_height_unit' => 'm'], 'Pole 001'));
        $this->assertSame(0.0, $rules->consumerCableLength(['pole_height' => 4, 'pole_height_unit' => 'ft'], 'Pole 001'));
    }

    public function test_missing_invalid_or_ambiguous_height_evidence_reports_the_record(): void
    {
        foreach ([[], ['pole_height' => 36], ['pole_height' => null, 'pole_height_unit' => 'ft'],
            ['pole_height' => INF, 'pole_height_unit' => 'ft'], ['pole_height' => 3, 'pole_height_unit' => 'ft'],
            ['pole_height' => 36, 'pole_height_unit' => 'unknown']] as $pole) {
            try {
                (new SectionGenerationRules)->consumerCableLength($pole, 'Transformer T-test, Pole 001');
                $this->fail('Missing or invalid source height must not receive a default length.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Transformer T-test, Pole 001', $exception->getMessage());
            }
        }
    }

    public function test_invalid_dates_shortened_waypoints_and_overlong_ids_are_rejected(): void
    {
        foreach ([['000407', '01', '2026-02-30', '001'], ['000407', '01', '2026-10-08', '1'],
            ['000407', '1', '2026-10-08', '001'], [str_repeat('0', 30), '01', '2026-10-08', '001']] as $arguments) {
            try {
                (new SectionGenerationRules)->identifier(...$arguments);
                $this->fail('Invalid or altered section identifiers must not be accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('export', $exception->errors());
            }
        }
    }
}
