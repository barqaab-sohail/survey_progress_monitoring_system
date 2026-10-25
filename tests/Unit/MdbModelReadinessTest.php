<?php

namespace Tests\Unit;

use App\Services\Mdb\ModelReadiness;
use PHPUnit\Framework\TestCase;

class MdbModelReadinessTest extends TestCase
{
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
