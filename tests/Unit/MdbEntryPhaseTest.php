<?php

namespace Tests\Unit;

use App\Services\Mdb\EntryLookups;
use PHPUnit\Framework\TestCase;

class MdbEntryPhaseTest extends TestCase
{
    public function test_old_abc_codes_resolve_to_exact_warehouse_names_without_changing_other_aliases(): void
    {
        foreach (['ABC 50mm2' => 'ABC 50 MM2', 'ABC 95mm2' => 'ABC 95 MM2', 'A' => 'ANT', 'W' => 'WASP', 'GN' => 'GNAT', 'DOG' => 'DOG', '' => ''] as $input => $expected) {
            $this->assertSame($expected, EntryLookups::libraryCode($input));
        }
        $this->assertNull(EntryLookups::libraryCode(null));
    }

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
