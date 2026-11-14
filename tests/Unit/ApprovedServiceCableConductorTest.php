<?php

namespace Tests\Unit;

use App\Services\Mdb\ServiceCableService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ApprovedServiceCableConductorTest extends TestCase
{
    public function test_service_cable_rules_allow_only_exact_approved_codes_or_an_unrecorded_value(): void
    {
        $rules = ['conductor_code' => (new ServiceCableService)->rules()['conductor_code']];
        foreach (['ANT', 'WASP', 'GNAT', 'PVC 7/0.052', null, ''] as $code) {
            $this->assertTrue(Validator::make(['conductor_code' => $code], $rules)->passes());
        }
        foreach (['A', 'W', 'G', 'GN', 'ABC 50mm2', 'invented cable'] as $code) {
            $this->assertTrue(Validator::make(['conductor_code' => $code], $rules)->fails());
        }
    }
}
