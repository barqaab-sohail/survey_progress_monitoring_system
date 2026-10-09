<?php

namespace Tests\Unit;

use App\Services\Mdb\EntryLookups;
use PHPUnit\Framework\TestCase;

class MdbEntryPhaseTest extends TestCase
{
    public function test_all_phase_combinations_exclude_neutral_and_distinguish_unknown_from_absent(): void
    {
        foreach (['R', 'Y', 'B', 'RY', 'RB', 'YB', 'RYB'] as $expected) {
            foreach ([null, '', 'A'] as $neutral) {
                $conductors = ['N' => $neutral];
                foreach (['R', 'Y', 'B'] as $phase) {
                    $conductors[$phase] = str_contains($expected, $phase) ? 'A' : '';
                }
                $derived = EntryLookups::phase($conductors);
                $this->assertSame($expected, $derived['display']);
                $this->assertTrue($derived['complete']);
            }
        }
        $this->assertFalse(EntryLookups::phase(['R' => 'A', 'Y' => null, 'B' => ''])['complete']);
        $this->assertTrue(EntryLookups::phase(['R' => 'A', 'Y' => '', 'B' => ''])['complete']);
        $this->assertSame('R', EntryLookups::phase(['R' => 'A', 'Y' => null, 'B' => '', 'N' => 'W'])['display']);
    }
}
