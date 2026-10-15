<?php

namespace Tests\Unit;

use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\NetworkValidator;
use App\Services\Mdb\SupplementalMapping;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbSupplementalMappingTest extends TestCase
{
    private function fixture(): array
    {
        $transformer = ['code' => 'T1', 'sections' => [['id' => 1, 'phases' => ['B', 'N']]], 'pv_records' => [['id' => 8, 'section_id' => 1, 'installed_capacity_kw' => 3]]];
        $configuration = ['approved_by' => 2, 'settings' => ['mapping' => [
            'table_values' => array_fill_keys(SupplementalMapping::TABLES, []),
            'networks' => ['T1' => ['source_sections' => [['key' => 'supply']], 'meter_points' => [['source_section' => 'supply']], 'generators' => [[
                'pv_record_id' => 8, 'fields' => ['DGenOutputPct' => 100, 'DGenVoltSet' => 120],
                'years' => [['YearNumber' => 0, 'CapacityFactor' => 1, 'Phase3Kw' => 3, 'Phase3Kvar' => -0.5]],
            ]]]],
        ]]];

        return [$transformer, $configuration];
    }

    public function test_inactive_generator_phases_remain_null_and_meters_do_not_invent_readings(): void
    {
        [$transformer, $configuration] = $this->fixture();
        $result = app(SupplementalMapping::class)->build($transformer, $configuration);
        $this->assertSame([['SectionId' => 'source:supply']], $result['InstMeterPoints']);
        $this->assertNull($result['InstMymDGens'][0]['Phase1Kw']);
        $this->assertNull($result['InstMymDGens'][0]['Phase2Kw']);
        $this->assertSame(3.0, $result['InstMymDGens'][0]['Phase3Kw']);
    }

    public function test_invalid_device_mappings_fail_with_validation_errors(): void
    {
        $mutations = [
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['meter_points'][0]['source_section'] = 'foreign',
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['meter_points'][] = ['source_section' => 'supply'],
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['meter_points'][0]['fields'] = ['SectionId' => 'old-project'],
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'][0]['pv_record_id'] = 99,
            fn (&$t, &$c) => $t['pv_records'][0]['section_id'] = 99,
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'][0]['years'][0]['Phase1Kw'] = 1,
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'][0]['years'][0]['Phase3Kw'] = null,
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'][0]['years'][0]['CapacityFactor'] = 2,
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'][0]['years'][0]['YearNumber'] = 1,
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'][0]['years'][] = ['YearNumber' => 0],
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'] = [],
            fn (&$t, &$c) => $c['settings']['mapping']['networks']['T1']['generators'][0]['fields']['DGenVoltSet'] = null,
        ];
        foreach ($mutations as $index => $mutate) {
            [$transformer, $configuration] = $this->fixture();
            $mutate($transformer, $configuration);
            try {
                app(SupplementalMapping::class)->build($transformer, $configuration);
                $this->fail('Invalid device mapping passed: '.$index);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('export', $exception->errors());
            }
        }
    }

    public function test_network_review_checks_v2_pv_mapping_before_export(): void
    {
        [$transformer, $configuration] = $this->fixture();
        $configuration['settings']['mapping']['version'] = ExportPayloadBuilder::EXTENDED_VERSION;
        $transformer['id'] = 1;
        // Other survey inputs are intentionally absent: assert the device-specific review result.
        $codes = array_column(app(NetworkValidator::class)->validate(['configuration' => $configuration, 'transformers' => [$transformer]])['errors'], 'code');
        $this->assertNotContains('pv_model_unmapped', $codes);
        $this->assertNotContains('invalid_device_mapping', $codes);
        $configuration['settings']['mapping']['networks']['T1']['generators'][0]['pv_record_id'] = 999;
        $codes = array_column(app(NetworkValidator::class)->validate(['configuration' => $configuration, 'transformers' => [$transformer]])['errors'], 'code');
        $this->assertContains('invalid_device_mapping', $codes);
    }
}
