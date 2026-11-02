<?php

namespace Tests\Unit;

use App\Services\Mdb\LoadsTableGeneration;
use App\Services\Mdb\InstMymLoadsGeneration;
use Tests\TestCase;

class MdbLoadsTableGenerationTest extends TestCase
{
    private function fixture(int $count = 14, array $phases = ['R', 'Y', 'B', 'N'], ?array $recorded = null): array
    {
        $pole = '00040708081026029';
        $feeder = '00040711140223003';
        $phaseCounts = $recorded ?? array_fill_keys(['R', 'Y', 'B'], $count / 3);
        $section = ['id' => 45, 'end_reference' => '029', 'phases' => $phases,
            'conductors' => array_replace(array_fill_keys(['R', 'Y', 'B', 'N'], ''), array_fill_keys($phases, 'ANT')),
            'consumers' => [['category' => 'sc', 'count' => $count, 'demand' => ['quantity' => 'kva', 'allocation_basis' => 'equal',
                'phase_values' => array_combine(['R', 'Y', 'B'], array_map(fn ($phase) => ['customers' => $phaseCounts[$phase] ?? 0, 'kva' => ($phaseCounts[$phase] ?? 0) * 2], ['R', 'Y', 'B']))]]]];
        $entries = [['section_id' => 45, 'designation' => 'E', 'composite_identifier' => '08081026029',
            'consumers' => ['sc' => $count], 'consumer_phases' => $recorded === null ? null : ['sc' => $recorded]]];
        $tables = ['InstFeeders' => [['FeederId' => 'NAWAN SHEHR']], 'InstSection' => [
            ['SectionId' => $feeder, 'FeederId' => 'NAWAN SHEHR', 'FromNodeId' => 'NAWAN SHEHR', 'ToNodeId' => 'S'],
            ['SectionId' => $feeder.'.1', 'FeederId' => 'NAWAN SHEHR', 'FromNodeId' => 'S', 'ToNodeId' => 'T'],
            ['SectionId' => $pole, 'FeederId' => 'NAWAN SHEHR', 'FromNodeId' => 'T', 'ToNodeId' => '81026029'],
        ], 'Loads' => [['SectionId' => $pole], ['SectionId' => $pole]]];
        foreach (range(1, $count) as $suffix) {
            $tables['InstSection'][] = ['SectionId' => $pole.'.'.$suffix, 'FeederId' => 'NAWAN SHEHR',
                'FromNodeId' => '81026029', 'ToNodeId' => '81026029.'.$suffix];
        }
        $contexts = [['transformer' => ['code' => '11140223003', 'entry_rows' => $entries, 'sections' => [$section]]]];
        $provenance = [['survey_section_id' => 45, 'section_id' => $pole]];
        $configuration = ['load_assumptions' => ['mode' => 'category_rates', 'rates' => ['sc' => ['kva' => 2]]],
            'settings' => ['mapping' => ['table_values' => ['Loads' => ['IsSpotLoad' => 0, 'CapacityFactor' => 0.75]]]]];

        return [$tables, $contexts, $provenance, $configuration];
    }

    public function test_all_actual_sections_except_the_feeder_have_one_text_id_row_and_whole_source_loads(): void
    {
        $args = $this->fixture();
        $before = $args;
        $rows = (new LoadsTableGeneration)->apply(...$args);
        $this->assertSame($before, $args);
        $this->assertCount(16, $rows);
        $byId = array_column($rows, null, 'SectionId');
        $this->assertCount(16, $byId);
        $this->assertArrayNotHasKey('00040711140223003', $byId);
        $this->assertArrayHasKey('00040711140223003.1', $byId);
        $pole = $byId['00040708081026029'];
        $this->assertEquals([5, 5, 4], [$pole['Phase1Customers'], $pole['Phase2Customers'], $pole['Phase3Customers']]);
        $this->assertEquals([10, 10, 8], [$pole['Phase1Kva'], $pole['Phase2Kva'], $pole['Phase3Kva']]);
        foreach (range(1, 14) as $suffix) {
            $row = $byId['00040708081026029.'.$suffix];
            $phase = (($suffix - 1) % 3) + 1;
            $this->assertSame(1, $row['Phase'.$phase.'Customers']);
            $this->assertEquals(2, $row['Phase'.$phase.'Kva']);
            $this->assertEquals(1, $row['Phase1Customers'] + $row['Phase2Customers'] + $row['Phase3Customers']);
        }
        $this->assertNotSame($byId['00040708081026029.1']['SectionId'], $byId['00040708081026029.10']['SectionId']);
        foreach ($rows as $row) {
            $this->assertCount(20, $row);
            $this->assertSame('', $row['Description']);
            $this->assertSame(0.75, $row['CapacityFactor']);
            foreach ([1, 2, 3] as $phase) {
                $value = $row['Phase'.$phase.'Customers'];
                $this->assertEquals(floor($value), $value);
            }
            foreach (['Phase1Kw', 'Phase2Kw', 'Phase3Kw', 'Phase1Kvar', 'Phase2Kvar', 'Phase3Kvar', 'Phase1Kwh', 'Phase2Kwh', 'Phase3Kwh'] as $field) {
                $this->assertSame(0, $row[$field]);
            }
        }
    }

    public function test_recorded_subset_allocation_is_preserved_without_using_unavailable_y_phase(): void
    {
        $rows = (new LoadsTableGeneration)->apply(...$this->fixture(5, ['R', 'B', 'N'], ['R' => 3, 'B' => 2]));
        $pole = array_column($rows, null, 'SectionId')['00040708081026029'];
        $this->assertEquals([3, 0, 2], [$pole['Phase1Customers'], $pole['Phase2Customers'], $pole['Phase3Customers']]);
        $this->assertEquals([6, 0, 4], [$pole['Phase1Kva'], $pole['Phase2Kva'], $pole['Phase3Kva']]);
        foreach ($rows as $row) {
            $this->assertEquals(0, $row['Phase2Customers']);
            $this->assertEquals(0, $row['Phase2Kva']);
        }
    }

    public function test_three_phase_connections_preserve_saved_total_kva_at_whole_unit_precision(): void
    {
        $args = $this->fixture(3);
        $consumer = &$args[1][0]['transformer']['sections'][0]['consumers'][0];
        $consumer['category'] = 'lc';
        $consumer['count'] = 1;
        $consumer['demand']['phase_values'] = array_fill_keys(['R', 'Y', 'B'], ['customers' => 1 / 3, 'kva' => 5 / 3]);
        $args[1][0]['transformer']['entry_rows'][0]['consumers'] = ['lc' => 1];
        $args[3]['load_assumptions']['rates']['lc'] = ['kva' => 5];
        $rows = array_column((new LoadsTableGeneration)->apply(...$args), null, 'SectionId');
        $pole = $rows['00040708081026029'];
        $this->assertEquals([1, 1, 1], [$pole['Phase1Customers'], $pole['Phase2Customers'], $pole['Phase3Customers']]);
        $this->assertEquals([2, 2, 1], [$pole['Phase1Kva'], $pole['Phase2Kva'], $pole['Phase3Kva']]);
        $sum = 0;
        foreach ([1, 2, 3] as $phase) {
            $sum += $rows['00040708081026029.'.$phase]['Phase'.$phase.'Kva'];
        }
        $this->assertEquals(5, $sum);
    }

    public function test_explicit_measured_demand_and_saved_blanks_keep_their_precision_and_totals(): void
    {
        $args = $this->fixture(3, ['R', 'N'], ['R' => 3]);
        $args[3]['load_assumptions']['mode'] = 'measured';
        $args[1][0]['transformer']['sections'][0]['consumers'][0]['demand'] = ['phase_values' => [
            'R' => ['customers' => 3, 'kw' => 2.4, 'kvar' => -1.8, 'kva' => 3, 'kwh' => 12],
        ]];
        $rows = array_column((new LoadsTableGeneration)->apply(...$args), null, 'SectionId');
        $pole = $rows['00040708081026029'];
        $this->assertSame(2.4, $pole['Phase1Kw']);
        $this->assertSame(-1.8, $pole['Phase1Kvar']);
        $this->assertSame(0, $pole['Phase2Kw']);
        $this->assertEqualsWithDelta(2.4, array_sum(array_map(fn ($i) => $rows['00040708081026029.'.$i]['Phase1Kw'], [1, 2, 3])), 1e-12);
        $this->assertEqualsWithDelta(-1.8, array_sum(array_map(fn ($i) => $rows['00040708081026029.'.$i]['Phase1Kvar'], [1, 2, 3])), 1e-12);
    }

    public function test_pv_only_connections_receive_rows_without_inventing_consumption_customers_or_demand(): void
    {
        $args = $this->fixture(3);
        $transformer = &$args[1][0]['transformer'];
        $transformer['sections'][0]['consumers'] = [];
        $transformer['entry_rows'][0]['consumers'] = ['pv' => 1];
        $transformer['entry_rows'][0]['pv_details'] = [['installed_capacity_kw' => 5]];
        $transformer['pv_records'] = [['section_id' => 45, 'installed_capacity_kw' => 5]];
        $rows = (new LoadsTableGeneration)->apply(...$args);
        $this->assertCount(5, $rows);
        $byId = array_column($rows, null, 'SectionId');
        $poleId = '00040708081026029';
        $pvId = $poleId.'.1';
        foreach ([$poleId, $pvId] as $id) {
            $this->assertEqualsWithDelta(1 / 3, $byId[$id]['Phase1Customers'], 1e-8);
            $this->assertEqualsWithDelta(1 / 3, $byId[$id]['Phase2Customers'], 1e-8);
            $this->assertEqualsWithDelta(1 / 3, $byId[$id]['Phase3Customers'], 1e-8);
            $this->assertEqualsWithDelta(5 / 3, $byId[$id]['Phase1Kva'], 1e-8);
            $this->assertEqualsWithDelta(5 / 3, $byId[$id]['Phase2Kva'], 1e-8);
            $this->assertEqualsWithDelta(5 / 3, $byId[$id]['Phase3Kva'], 1e-8);
        }
        foreach ([$poleId.'.2', $poleId.'.3'] as $id) {
            $this->assertEquals([0, 0, 0], [$byId[$id]['Phase1Customers'], $byId[$id]['Phase2Customers'], $byId[$id]['Phase3Customers']]);
            $this->assertEquals([0, 0, 0], [$byId[$id]['Phase1Kva'], $byId[$id]['Phase2Kva'], $byId[$id]['Phase3Kva']]);
        }
        $annual = (new InstMymLoadsGeneration)->apply($rows, $args[0]['InstSection']);
        $pvAnnual = array_values(array_filter($annual, fn ($row) => $row['SectionId'] === $pvId && $row['YearNumber'] === 1));
        $this->assertCount(1, $pvAnnual);
        $this->assertEqualsWithDelta(1 / 3, $pvAnnual[0]['Phase1Customers'], 1e-8);
        $this->assertEqualsWithDelta(5 / 3, $pvAnnual[0]['Phase1Kva'], 1e-8);
        foreach ($rows as $row) {
            foreach ([1, 2, 3] as $phase) {
                $this->assertSame(0, $row['Phase'.$phase.'Kw']);
            }
        }
    }
}
