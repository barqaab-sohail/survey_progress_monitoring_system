<?php

namespace Tests\Unit;

use App\Services\Mdb\RecordedConsumerPhases;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordedConsumerPhasesTest extends TestCase
{
    public function test_total_only_rows_fall_back_and_explicit_zero_phase_entries_are_retained(): void
    {
        $reader = new RecordedConsumerPhases;
        $this->assertNull($reader->fromRows([['consumers' => ['rs' => 10],
            'consumer_phases' => ['rs' => ['R' => null, 'Y' => null, 'B' => null]]]], 'rs'));
        $this->assertSame(['R' => 0, 'Y' => 0, 'B' => 3], $reader->fromRows([['consumers' => ['sc' => 3],
            'original_entry' => ['consumer_phases' => ['sc' => ['R' => 0, 'Y' => null, 'B' => 3]]]]], 'sc'));
        $this->assertNull($reader->fromRows([['consumer_phases' => ['rl' => ['R' => 1]]]], 'rl'));
    }

    public function test_phase_totals_must_match_the_entered_row_total(): void
    {
        $this->expectException(ValidationException::class);
        (new RecordedConsumerPhases)->fromRows([['consumers' => ['rs' => 10],
            'consumer_phases' => ['rs' => ['R' => 8, 'Y' => 1, 'B' => 0]]]], 'rs');
    }
}
