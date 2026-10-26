<?php

namespace Tests\Unit;

use App\Services\SurveyProgress\SurveyInventory;
use PHPUnit\Framework\TestCase;

class SurveyProgressInventoryTest extends TestCase
{
    private function pair(string $name): array
    {
        return [['id' => $name.'pdf', 'name' => $name.'.pdf', 'md5Checksum' => md5($name.'pdf')], ['id' => $name.'gpx', 'name' => $name.'.gpx', 'md5Checksum' => md5($name.'gpx')]];
    }

    public function test_filename_quantities_total_nineteen_and_aggregate_by_group_and_date(): void
    {
        $result = (new SurveyInventory)->analyze(array_merge($this->pair('A_10102026_05'), $this->pair('B_10102026_08'), $this->pair('A_11102026_06')));
        $this->assertSame(19, $result['count']);
        $this->assertSame(['A' => 11, 'B' => 8], $result['by_group']);
        $this->assertSame(['2026-10-10' => 13, '2026-10-11' => 6], $result['by_date']);
    }

    public function test_identical_copies_and_leading_zero_variants_count_once(): void
    {
        $files = $this->pair('A_10102026_05');
        $copy = $files[0];
        $copy['id'] = 'copy';
        $copy['name'] = 'a_10102026_5.PDF';
        $files[] = $copy;
        $result = (new SurveyInventory)->analyze($files);
        $this->assertSame(5, $result['count']);
        $this->assertContains('duplicate_files_deduplicated', array_column($result['issues'], 'code'));
    }

    public function test_drive_six_digit_dates_count_filename_quantities_once_per_pair(): void
    {
        $result = (new SurveyInventory)->analyze(array_merge(
            $this->pair('A_081026_05'), $this->pair('A_091026_13'),
            $this->pair('B_081026_5'), $this->pair('C_081026_08'),
            $this->pair('C_091026_09'),
        ));

        $this->assertSame(40, $result['count']);
        $this->assertCount(5, $result['pairs']);
        $this->assertSame(['A' => 18, 'B' => 5, 'C' => 17], $result['by_group']);
        $this->assertSame(['2026-10-08' => 18, '2026-10-09' => 22], $result['by_date']);
        $this->assertSame(['missing_kmz'], array_column($result['issues'], 'code'));
    }

    public function test_short_and_long_date_formats_match_and_share_conflict_checks(): void
    {
        $inventory = new SurveyInventory;
        $files = [$this->pair('A_081026_05')[0], $this->pair('a_08102026_5')[1]];
        $this->assertSame(5, $inventory->analyze($files)['count']);
        $this->assertSame('A_08102026_5', $inventory->key('A_081026_05.pdf'));
        $this->assertNull($inventory->key('A_310226_05.pdf'));
        $this->assertNull($inventory->key('A_081026_00.pdf'));
        $this->assertSame(0, $inventory->analyze(array_merge($files, $this->pair('A_081026_06')))['count']);
    }

    public function test_incomplete_pairs_conflicting_quantities_and_conflicting_copies_are_excluded(): void
    {
        $files = array_merge($this->pair('A_10102026_05'), $this->pair('A_10102026_06'), [$this->pair('B_11102026_07')[0]]);
        $this->assertSame(0, (new SurveyInventory)->analyze($files)['count']);
        $files = $this->pair('A_10102026_05');
        $copy = $files[0];
        $copy['id'] = 'different';
        $copy['md5Checksum'] = md5('different');
        $files[] = $copy;
        $this->assertSame(0, (new SurveyInventory)->analyze($files)['count']);
    }

    public function test_impossible_dates_are_not_counted(): void
    {
        $inventory = new SurveyInventory;
        $this->assertNull($inventory->key('A_31022026_05.pdf'));
        $this->assertNull($inventory->key('A_10102026_00.pdf'));
    }
}
