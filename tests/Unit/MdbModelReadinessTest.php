<?php

namespace Tests\Unit;

use App\Services\Mdb\ModelReadiness;
use PHPUnit\Framework\TestCase;

class MdbModelReadinessTest extends TestCase
{
    public function test_kva_only_demand_counts_as_populated_but_missing_active_phase_values_do_not(): void
    {
        $payload = ['tables' => [
            'Node' => [], 'InstPrimaryTransformers' => [],
            'InstSection' => [['SectionId' => 's', 'SectionPhases' => 'RY N']],
            'Loads' => [['SectionId' => 's',
                'Phase1Customers' => 2, 'Phase1Kva' => 4, 'Phase1Kw' => null, 'Phase1Kvar' => null,
                'Phase2Customers' => 0, 'Phase2Kva' => 0, 'Phase2Kw' => null, 'Phase2Kvar' => null]],
        ]];
        $service = new ModelReadiness;
        $this->assertSame(1, $service->inspect($payload)['checks'][5]['complete']);
        foreach (['Phase1Kva', 'Phase1Customers', 'Phase2Kva', 'Phase2Customers'] as $field) {
            $missing = $payload;
            $missing['tables']['Loads'][0][$field] = null;
            $this->assertSame(1, $service->inspect($missing)['checks'][5]['missing'], $field);
        }
        $payload['tables']['InstSection'][0]['SectionPhases'] = '';
        $this->assertSame(1, $service->inspect($payload)['checks'][5]['missing']);
    }

    public function test_completeness_requires_coordinates_lengths_type_configuration_and_active_phase_demand(): void
    {
        $payload = ['tables' => [
            'Node' => [['X' => 0, 'Y' => 0]],
            'InstSection' => [['SectionId' => 's', 'SectionLength_MUL' => 0.0, 'ConfigurationId' => 'confirmed', 'SectionPhases' => 'R  N']],
            'InstPrimaryTransformers' => [['TransformerType' => 'confirmed-100']],
            'Loads' => [['SectionId' => 's', 'Phase1Kw' => 0, 'Phase1Kvar' => 0, 'Phase1Kva' => 0, 'Phase1Customers' => 0]],
        ]];
        $service = new ModelReadiness;
        $this->assertTrue($service->inspect($payload)['complete']);
        $payload['tables']['Node'][0]['X'] = null;
        $payload['tables']['Loads'][0]['Phase1Kva'] = null;
        $result = $service->inspect($payload);
        $this->assertFalse($result['complete']);
        $this->assertSame(1, $result['checks'][0]['missing']);
        $this->assertSame(1, $result['checks'][5]['missing']);
    }
}
