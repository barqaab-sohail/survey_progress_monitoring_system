<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use App\Services\Mdb\AccessPowerShell;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\InstMymLoadsGeneration;
use App\Services\Mdb\InstSectionGeneration;
use App\Services\Mdb\ProjectionService;
use App\Services\Mdb\ReadbackVerifier;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MdbInstSectionGenerationTest extends TestCase
{
    public static function snapshot(): array
    {
        $s = MdbNodeTableGenerationTest::snapshot();
        $t = &$s['transformers'][0];
        $t['header']['feeder_identifier'] = '407';
        foreach ($t['sections'] as &$section) {
            $section['phases'] = ['R', 'Y', 'B', 'N'];
            $section['conductors'] = array_fill_keys(['R', 'Y', 'B', 'N'], 'ANT');
        }
        unset($section);
        foreach ($t['entry_rows'] as &$row) {
            $row['pole_height'] = $row['designation'] === 'E' && $row['pair_number'] === 2 ? 31 : 36;
            $row['pole_height_unit'] = 'ft';
            if ($row['designation'] === 'S') {
                $row['conductors'] = array_fill_keys(['R', 'Y', 'B', 'N'], 'ANT');
            }
        }
        unset($row);
        $s['configuration']['settings']['mapping']['table_values']['InstSection'] += ['Note_' => 'Keep notes', 'CostZone' => 'Keep zone', 'ExpFactRepair' => 99];

        return $s;
    }

    private function build(array $s, ?int $transformerId = 1): array
    {
        $revision = new Revision(['batch_id' => 1, 'number' => 1, 'sha256' => str_repeat('a', 64), 'snapshot' => $s]);
        $template = new Template($s['template'] + ['active' => true]);

        return app(ExportPayloadBuilder::class)->build($revision, $template, $transformerId, str_repeat('b', 64));
    }

    public static function multipleTransformersSnapshot(): array
    {
        $s = self::snapshot();
        $mapping = &$s['configuration']['settings']['mapping'];
        $mapping['consolidated_supported'] = true;
        $mapping['networks']['T-SYNTHETIC']['transformer_fields'] = ['PercentResistance' => 0.375];
        $mapping['networks']['T-SECOND']['transformer_fields'] = ['PercentResistance' => 0.625];
        $second = $s['transformers'][0];
        $second['id'] = 2;
        $second['code'] = 'T-SECOND';
        $second['source_waypoint_id'] = 3;
        $second['header']['feeder_identifier'] = '408';
        $second['header']['feeder_name'] = 'Second fixture feeder';
        $references = ['104' => '105', '001' => '003', '002' => '004'];
        foreach ($second['sections'] as &$section) {
            $section['id'] += 2;
            foreach (['start', 'end'] as $end) {
                if ($section[$end.'_waypoint_id'] !== null) {
                    $section[$end.'_waypoint_id'] += 2;
                }
                $section[$end.'_reference'] = $references[$section[$end.'_reference']];
                $identity = $section['original_entry'][$end.'_survey_identifier'];
                $section['original_entry'][$end.'_survey_identifier'] = substr($identity, 0, 8).$references[substr($identity, -3)];
            }
        }
        unset($section);
        foreach ($second['entry_rows'] as &$row) {
            $row['id'] += 4;
            $row['section_id'] += 2;
            $row['waypoint_reference'] = $references[$row['waypoint_reference']];
            $row['composite_identifier'] = substr($row['composite_identifier'], 0, 8).$row['waypoint_reference'];
        }
        unset($row);
        foreach ($s['waypoints'] as $point) {
            $point['id'] += 2;
            $point['name'] = $references[$point['name']];
            $s['waypoints'][] = $point;
        }
        $s['transformers'][] = $second;

        return $s;
    }

    public function test_each_primary_transformer_reuses_its_transformer_section_and_preserves_other_fields(): void
    {
        $s = self::multipleTransformersSnapshot();
        $before = $s;
        $payload = $this->build($s, null);
        $this->assertSame($before, $s);
        foreach ($s['transformers'] as &$transformer) {
            foreach ($transformer['entry_rows'] as &$entry) {
                unset($entry['composite_identifier']);
            }
            unset($entry);
        }
        unset($transformer);
        $baseline = array_column($this->build($s, null)['tables']['InstPrimaryTransformers'], null, 'UniqueDeviceId');
        $sections = array_column($payload['tables']['InstSection'], null, 'SectionId');
        $expected = ['T-SYNTHETIC' => '00040711131222104.1', 'T-SECOND' => '00040811131222105.1'];
        $this->assertCount(2, $payload['tables']['InstPrimaryTransformers']);
        foreach ($payload['tables']['InstPrimaryTransformers'] as $row) {
            $id = $row['UniqueDeviceId'];
            $this->assertSame($expected[$id], $row['SectionId']);
            $this->assertSame('Transformer', $sections[$row['SectionId']]['Description']);
            $old = $baseline[$id];
            unset($old['SectionId'], $row['SectionId']);
            $this->assertSame($old, $row, $id.' non-SectionId fields changed');
        }
    }

    public function test_primary_transformer_cannot_reference_a_missing_or_unrelated_section(): void
    {
        $tables = $this->build(self::snapshot())['tables'];
        $primary = $tables['InstPrimaryTransformers'][0];
        $contexts = [['transformer' => ['code' => $primary['UniqueDeviceId'], 'entry_rows' => []],
            'network' => ['transformer_section' => 'existing-link'],
            'source_section_ids' => ['existing-link' => $primary['SectionId']]]];
        foreach (['missing', $tables['InstSection'][0]['SectionId'], 'wrong-transformer'] as $invalid) {
            $invalidTables = $tables;
            if ($invalid === 'wrong-transformer') {
                $invalidTables['InstPrimaryTransformers'][0]['UniqueDeviceId'] = 'Another transformer';
            } else {
                $invalidTables['InstPrimaryTransformers'][0]['SectionId'] = $invalid;
            }
            try {
                (new InstSectionGeneration)->apply($invalidTables, $contexts, [], []);
                $this->fail('A primary transformer must use its existing transformer-section relationship.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('no matching transformer section relationship', $exception->getMessage());
            }
        }
    }

    public static function mixedSnapshot(int $singlePhaseCount = 3, bool $recorded = true): array
    {
        $s = self::snapshot();
        $t = &$s['transformers'][0];
        $t['sections'][0]['consumers'] = [['category' => 'rs', 'count' => $singlePhaseCount], ['category' => 'rl', 'count' => 1]];
        $t['entry_rows'][1]['consumers'] = ['rs' => $singlePhaseCount, 'rl' => 1, 'pv' => 1];
        if ($recorded) {
            $t['entry_rows'][1]['consumer_phases'] = ['rs' => ['R' => $singlePhaseCount]];
        }
        $t['entry_rows'][1]['pv_details'] = [['reference' => 'PV-test', 'installed_capacity_kw' => 5]];
        $t['pv_records'] = [['id' => 1, 'section_id' => 1, 'entry_row_id' => 2, 'reference' => 'PV-test', 'installed_capacity_kw' => 5]];
        $t['sections'][0]['pv_records'] = $t['pv_records'];

        return $s;
    }

    public static function yOnlyPvSnapshot(): array
    {
        $s = self::snapshot();
        $t = &$s['transformers'][0];
        $s['waypoints'][1]['name'] = '014';
        $t['sections'][1]['end_reference'] = '014';
        $t['sections'][1]['original_entry']['end_survey_identifier'] = '01081026014';
        $t['sections'][1]['phases'] = ['Y', 'N'];
        $t['sections'][1]['conductors'] = ['Y' => 'ANT', 'N' => 'ANT'];
        $t['sections'][1]['consumers'] = [['category' => 'rs', 'count' => 8]];
        $t['entry_rows'][2]['conductors'] = ['Y' => 'A', 'N' => 'A'];
        $t['entry_rows'][3]['waypoint_reference'] = '014';
        $t['entry_rows'][3]['composite_identifier'] = '01081026014';
        $t['entry_rows'][3]['pole_height'] = 36;
        $t['entry_rows'][3]['consumers'] = ['rs' => 8, 'pv' => 1];
        $t['entry_rows'][3]['consumer_phases'] = ['rs' => ['Y' => 8]];
        $t['entry_rows'][3]['pv_details'] = [['reference' => 'PV-014', 'installed_capacity_kw' => 5]];
        $t['pv_records'] = [['id' => 1, 'section_id' => 2, 'entry_row_id' => 4, 'reference' => 'PV-014', 'installed_capacity_kw' => 5]];
        $t['sections'][1]['pv_records'] = $t['pv_records'];

        return $s;
    }

    public static function limitedPhaseSnapshot(array $phases): array
    {
        $s = self::mixedSnapshot(0, false);
        $t = &$s['transformers'][0];
        $t['sections'][0]['phases'] = [...$phases, 'N'];
        $t['sections'][0]['conductors'] = array_fill_keys([...$phases, 'N'], 'ANT');
        $t['entry_rows'][0]['conductors'] = array_fill_keys([...$phases, 'N'], 'A');
        $t['sections'][0]['consumers'][1]['demand'] = ['method' => 'Synthetic category rate', 'evidence' => 'Test evidence', 'approved_by' => 1,
            'quantity' => 'kva', 'allocation_basis' => 'equal', 'phase_values' => array_fill_keys($phases, ['customers' => 1 / count($phases), 'kva' => 5 / count($phases)])];

        return $s;
    }

    public static function availablePhaseCases(): array
    {
        return ['R' => [['R'], ['RN', 'RN', 'RN']], 'Y' => [['Y'], ['YN', 'YN', 'YN']], 'B' => [['B'], ['BN', 'BN', 'BN']],
            'RY' => [['R', 'Y'], ['RN', 'YN', 'RN']], 'RB' => [['R', 'B'], ['RN', 'BN', 'RN']],
            'YB' => [['Y', 'B'], ['YN', 'BN', 'YN']], 'RYB' => [['R', 'Y', 'B'], ['RN', 'YN', 'BN']]];
    }

    #[DataProvider('availablePhaseCases')]
    public function test_three_phase_consumers_and_pv_use_only_available_conductors_and_preserve_totals(array $phases, array $expected): void
    {
        $s = self::limitedPhaseSnapshot($phases);
        $before = $s;
        $payload = $this->build($s);
        $sections = array_column($payload['tables']['InstSection'], null, 'SectionId');
        $loads = array_column($payload['tables']['Loads'], null, 'SectionId');
        $pole = '00040701081026001';
        $pv = $pole.'.4';
        $consumerKva = 0;
        $nodes = array_column($payload['tables']['Node'], null, 'NodeId');
        foreach ($expected as $index => $code) {
            $id = $pole.'.'.($index + 1);
            $this->assertSame($code, $sections[$id]['SectionPhases']);
            $this->assertArrayHasKey($sections[$id]['ToNodeId'], $nodes);
            $this->assertEquals(1, $loads[$id]['Phase1Customers'] + $loads[$id]['Phase2Customers'] + $loads[$id]['Phase3Customers']);
            $consumerKva += $loads[$id]['Phase1Kva'] + $loads[$id]['Phase2Kva'] + $loads[$id]['Phase3Kva'];
        }
        $this->assertEqualsWithDelta(5, $consumerKva, 1e-8);
        $this->assertSame(implode('', $phases).'N', $sections[$pv]['SectionPhases']);
        $this->assertArrayNotHasKey($pole.'.5', $sections);
        $this->assertEqualsWithDelta(1, $loads[$pv]['Phase1Customers'] + $loads[$pv]['Phase2Customers'] + $loads[$pv]['Phase3Customers'], 1e-8);
        $this->assertEqualsWithDelta(5, $loads[$pv]['Phase1Kva'] + $loads[$pv]['Phase2Kva'] + $loads[$pv]['Phase3Kva'], 1e-8);
        $this->assertEqualsWithDelta(10, $loads[$pole]['Phase1Kva'] + $loads[$pole]['Phase2Kva'] + $loads[$pole]['Phase3Kva'], 1e-8);
        $this->assertEquals(4, $loads[$pole]['Phase1Customers'] + $loads[$pole]['Phase2Customers'] + $loads[$pole]['Phase3Customers']);
        $weights = array_count_values(array_map(fn ($phase) => substr($phase, 0, 1), $expected));
        $annual = collect($payload['tables']['InstMymLoads'])->first(fn ($row) => $row['SectionId'] === $pv && $row['YearNumber'] === 1);
        foreach (['R', 'Y', 'B'] as $index => $phase) {
            $share = ($weights[$phase] ?? 0) / 3;
            $this->assertEqualsWithDelta($share, $loads[$pv]['Phase'.($index + 1).'Customers'], 1e-8);
            $this->assertEqualsWithDelta(5 * $share, $loads[$pv]['Phase'.($index + 1).'Kva'], 1e-8);
            $this->assertEqualsWithDelta(5 * $share, $annual['Phase'.($index + 1).'Kva'], 1e-8);
            if (! in_array($phase, $phases, true)) {
                $field = ['PhaseConductorId', 'PhaseConductor2Id', 'PhaseConductor3Id'][$index];
                foreach ([$pole, $pv] as $id) {
                    $this->assertNull($sections[$id][$field]);
                    $this->assertEquals(0, $loads[$id]['Phase'.($index + 1).'Customers']);
                    $this->assertEquals(0, $loads[$id]['Phase'.($index + 1).'Kva']);
                }
            }
        }
        $this->assertSame($before, $s);
    }

    public function test_y_and_neutral_only_pv_keeps_one_yn_connection_without_r_or_b_conductors(): void
    {
        $s = self::yOnlyPvSnapshot();
        $before = $s;
        $payload = $this->build($s);
        $rows = array_column($payload['tables']['InstSection'], null, 'SectionId');
        $nodes = array_column($payload['tables']['Node'], null, 'NodeId');
        $this->assertSame(' Y N', $rows['00040701081026014']['SectionPhases']);
        $this->assertNull($rows['00040701081026014']['PhaseConductorId']);
        $this->assertNull($rows['00040701081026014']['PhaseConductor3Id']);
        $row = $rows['00040701081026014.9'];
        $this->assertSame('YN', $row['SectionPhases']);
        $this->assertSame('81026014', $row['FromNodeId']);
        $this->assertSame('81026014.9', $row['ToNodeId']);
        $this->assertNull($row['PhaseConductorId']);
        $this->assertNull($row['PhaseConductor3Id']);
        $this->assertSame('PVC 07/0.052', $row['PhaseConductor2Id']);
        $this->assertSame('PVC 07/0.052', $row['NeutralConductorId']);
        $this->assertSame(14.62, $row['SectionLength_MUL']);
        $this->assertSame($nodes['81026014']['X'] + 0.01, $nodes[$row['ToNodeId']]['X']);
        $this->assertSame($nodes['81026014']['Y'] + 0.01, $nodes[$row['ToNodeId']]['Y']);
        $this->assertArrayNotHasKey('00040701081026014.10', $rows);
        $this->assertArrayNotHasKey('00040701081026014.11', $rows);
        $this->assertSame($before, $s);
    }

    public function test_three_phase_pv_uses_only_suffix_one_and_balanced_loads_in_both_tables(): void
    {
        $s = self::mixedSnapshot();
        $t = &$s['transformers'][0];
        $t['sections'][0]['consumers'] = [];
        $t['entry_rows'][1]['consumers'] = ['pv' => 1];
        unset($t['entry_rows'][1]['consumer_phases']);
        $payload = $this->build($s);
        $sectionId = '00040701081026001.1';
        $sections = array_column($payload['tables']['InstSection'], null, 'SectionId');
        $this->assertArrayHasKey($sectionId, $sections);
        $this->assertSame('RYBN', $sections[$sectionId]['SectionPhases']);
        $this->assertArrayNotHasKey('00040701081026001.2', $sections);
        $this->assertArrayNotHasKey('00040701081026001.3', $sections);

        foreach (['00040701081026001', $sectionId] as $id) {
            $load = collect($payload['tables']['Loads'])->firstWhere('SectionId', $id);
            $this->assertNotNull($load);
            foreach ([1, 2, 3] as $phase) {
                $this->assertEqualsWithDelta(1 / 3, $load['Phase'.$phase.'Customers'], 1e-8);
                $this->assertEqualsWithDelta(5 / 3, $load['Phase'.$phase.'Kva'], 1e-8);
            }
            $annual = collect($payload['tables']['InstMymLoads'])->first(fn ($row) => $row['SectionId'] === $id && $row['YearNumber'] === 1);
            $this->assertNotNull($annual);
            foreach ([1, 2, 3] as $phase) {
                $this->assertEqualsWithDelta(1 / 3, $annual['Phase'.$phase.'Customers'], 1e-8);
                $this->assertEqualsWithDelta(5 / 3, $annual['Phase'.$phase.'Kva'], 1e-8);
            }
        }
    }

    public function test_y_only_pv_still_requires_its_y_conductor_and_neutral(): void
    {
        foreach (['Y', 'N'] as $missing) {
            $s = self::yOnlyPvSnapshot();
            $s['transformers'][0]['sections'][1]['conductors'][$missing] = null;
            try {
                $this->build($s);
                $this->fail('An active Y/neutral conductor must not be invented.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Transformer T-SYNTHETIC', $exception->getMessage());
            }
        }
    }

    public function test_recorded_rn_customers_and_three_phase_consumer_and_pv_have_seven_connections(): void
    {
        $s = self::mixedSnapshot();
        $before = $s;
        $payload = $this->build($s);
        $children = array_values(array_filter($payload['tables']['InstSection'], fn ($r) => $r['FromNodeId'] === '81026001'));
        // Exclude the outgoing pole span: consumer/PV endpoints have a decimal suffix.
        $children = array_values(array_filter($children, fn ($r) => str_starts_with($r['ToNodeId'], '81026001.')));
        $this->assertCount(7, $children);
        $this->assertSame(['RN' => 2, 'YN' => 2, 'BN' => 2, 'RYBN' => 1], array_count_values(array_column($children, 'SectionPhases')));
        $nodes = array_column($payload['tables']['Node'], null, 'NodeId');
        foreach (range(1, 7) as $i) {
            $this->assertArrayHasKey('81026001.'.$i, $nodes);
            $this->assertSame($nodes['81026001']['X'] + 0.01, $nodes['81026001.'.$i]['X']);
            $this->assertSame($nodes['81026001']['Y'] + 0.01, $nodes['81026001.'.$i]['Y']);
            $this->assertSame('', $nodes['81026001.'.$i]['Description']);
        }
        $this->assertSame('RYBN', $children[6]['SectionPhases']);
        $this->assertCount(7, array_unique(array_column($children, 'ToNodeId')));
        $this->assertCount(7, array_unique(array_column($children, 'SectionId')));
        $this->assertSame($before, $s);
    }

    public function test_eight_unallocated_customers_plus_three_phase_consumer_and_pv_keep_one_pv_connection(): void
    {
        $payload = $this->build(self::mixedSnapshot(8, false));
        $children = array_values(array_filter($payload['tables']['InstSection'], fn ($r) => $r['FromNodeId'] === '81026001'
            && str_starts_with($r['ToNodeId'], '81026001.')));
        $this->assertCount(12, $children);
        $this->assertSame(['RN' => 4, 'YN' => 4, 'BN' => 3, 'RYBN' => 1], array_count_values(array_column($children, 'SectionPhases')));
        $nodes = array_column($payload['tables']['Node'], null, 'NodeId');
        foreach (range(1, 12) as $i) {
            $this->assertArrayHasKey('81026001.'.$i, $nodes);
        }
        $this->assertSame('81026001.12', $children[11]['ToNodeId']);
        $this->assertSame('RYBN', $children[11]['SectionPhases']);
        $this->assertNotSame($children[0]['ToNodeId'], $children[11]['ToNodeId']);
    }

    public function test_fourteen_single_phase_customers_rotate_compact_section_phase_codes(): void
    {
        $s = self::snapshot();
        $s['transformers'][0]['sections'][0]['consumers'] = [['category' => 'sc', 'count' => 14]];
        $s['transformers'][0]['entry_rows'][1]['consumers'] = ['sc' => 14, 'rs' => 0, 'rl' => 0, 'pv' => 0];
        $s['transformers'][0]['entry_rows'][1]['consumer_phases'] = ['sc' => ['R' => 14]];
        $s['transformers'][0]['entry_rows'][1]['pv_details'] = [];
        $s['transformers'][0]['pv_records'] = [];
        $s['transformers'][0]['sections'][0]['pv_records'] = [];

        $payload = $this->build($s);
        $sections = array_column($payload['tables']['InstSection'], null, 'SectionId');
        $expected = ['RN', 'YN', 'BN', 'RN', 'YN', 'BN', 'RN', 'YN', 'BN', 'RN', 'YN', 'BN', 'RN', 'YN'];
        foreach ($expected as $index => $phase) {
            $suffix = $index + 1;
            $id = '00040701081026001.'.$suffix;
            $row = $sections[$id];
            $this->assertSame($phase, $row['SectionPhases']);
            $this->assertDoesNotMatchRegularExpression('/\\s/', $row['SectionPhases']);
            $this->assertSame('81026001.'.$suffix, $row['ToNodeId']);
            $this->assertSame('81026001', $row['FromNodeId']);
        }
    }

    public function test_expansion_preserves_existing_physical_consumer_nodes_and_all_pole_nodes(): void
    {
        $s = self::mixedSnapshot();
        $expanded = $this->build($s);
        // Before expansion the same physical count was represented by four single endpoints.
        $s['transformers'][0]['sections'][0]['consumers'] = [['category' => 'rs', 'count' => 4]];
        $s['transformers'][0]['entry_rows'][1]['consumers'] = ['rs' => 4, 'pv' => 0];
        unset($s['transformers'][0]['entry_rows'][1]['consumer_phases']);
        $s['transformers'][0]['entry_rows'][1]['pv_details'] = [];
        $s['transformers'][0]['pv_records'] = $s['transformers'][0]['sections'][0]['pv_records'] = [];
        $before = $this->build($s);
        $nodes = array_column($expanded['tables']['Node'], null, 'NodeId');
        foreach ($before['tables']['Node'] as $node) {
            $this->assertSame($node, $nodes[$node['NodeId']]);
        }
        $this->assertCount(count($before['tables']['Node']) + 3, $nodes);
    }

    public function test_three_phase_items_still_reject_conflicting_pv_counts(): void
    {
        $s = self::limitedPhaseSnapshot(['R', 'B']);
        $s['transformers'][0]['entry_rows'][1]['pv_details'][] = ['reference' => 'PV-conflict'];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('PV count and installation records disagree');
        $this->build($s);
    }

    public function test_workbook_chain_ids_role_values_and_endpoint_heights_reach_normal_payload(): void
    {
        $s = self::snapshot();
        $payload = $this->build($s);
        $rows = $payload['tables']['InstSection'];
        $this->assertCount(21, $rows);
        $this->assertSame(['Synthetic feeder', '131222104', '131222104.1'], array_column(array_slice($rows, 0, 3), 'FromNodeId'));
        $this->assertSame(['131222104', '131222104.1', '81026001'], array_column(array_slice($rows, 0, 3), 'ToNodeId'));
        $this->assertSame(['00040711131222104', '00040711131222104.1', '00040701081026001'], array_column(array_slice($rows, 0, 3), 'SectionId'));
        $this->assertSame([null, 0.0, 10.0], array_column(array_slice($rows, 0, 3), 'SectionLength_MUL'));
        $this->assertSame(['Feeder', 'Transformer', 'Secondary Pole'], array_column(array_slice($rows, 0, 3), 'Description'));
        $this->assertSame(['500 MCM', '4/C 07/.052', 'ANT'], array_column(array_slice($rows, 0, 3), 'PhaseConductorId'));
        $this->assertSame(['RYB', 'RYBN', 'RYBN'], array_column(array_slice($rows, 0, 3), 'SectionPhases'));
        $this->assertSame(['RN', 'YN', 'BN'], array_column(array_slice($rows, 3, 3), 'SectionPhases'));
        $byId = array_column($rows, null, 'SectionId');
        $this->assertSame(14.62, $byId['00040701081026001.1']['SectionLength_MUL']);
        $this->assertSame(12.34, $byId['00040701081026002.1']['SectionLength_MUL']);
        $this->assertSame('PVC 07/0.052', $byId['00040701081026002.10']['NeutralConductorId']);
        $this->assertSame('81026002.10', $byId['00040701081026002.10']['ToNodeId']);
        $span = app(ProjectionService::class)->length($s['waypoints'], 32643);
        $this->assertEqualsWithDelta($span, $byId['00040701081026002']['SectionLength_MUL'], 1e-8);
        foreach ($rows as $row) {
            $this->assertSame(0, $row['PositionCode']);
            $this->assertSame(0, $row['ExpFactRepair']);
            $this->assertSame('Keep notes', $row['Note_']);
            $this->assertSame('Keep zone', $row['CostZone']);
            $this->assertSame('Synthetic feeder', $row['FeederId']);
        }
        $this->assertSame($s['configuration']['settings'], $payload['settings']['electrical']);
        $this->assertSame('workbook_static', $payload['provenance'][0]['length_source']);
        $this->assertSame(10.0, $payload['provenance'][0]['model_length_m']);
    }

    public function test_loads_cover_final_sections_with_text_ids_blank_descriptions_and_saved_settings(): void
    {
        $s = self::snapshot();
        $s['configuration']['settings']['mapping']['table_values']['Loads'] = [
            'Description' => 'internal process flag', 'CapacityFactor' => 0.75,
        ];
        $before = $this->build($s);
        $sectionIds = array_column($before['tables']['InstSection'], null, 'SectionId');
        $this->assertCount(count($sectionIds) - 1, $before['tables']['Loads']);
        $this->assertCount(count($before['tables']['Loads']), array_unique(array_column($before['tables']['Loads'], 'SectionId')));
        foreach ($before['tables']['Loads'] as $index => $load) {
            $this->assertArrayHasKey($load['SectionId'], $sectionIds);
            $this->assertStringStartsNotWith('s', $load['SectionId']);
            $this->assertSame('', $load['Description']);
            $this->assertSame(0.75, $load['CapacityFactor']);
        }
    }

    public function test_annual_loads_cover_every_eligible_section_in_both_years_with_no_blank_values(): void
    {
        $s = self::snapshot();
        $s['configuration']['settings']['mapping']['table_values']['Loads'] = ['IsSpotLoad' => 0, 'CapacityFactor' => 0.85];
        $payload = $this->build($s);
        $yearsById = $keys = [];
        foreach ($payload['tables']['InstMymLoads'] as $row) {
            $this->assertCount(19, $row);
            foreach ($row as $value) {
                $this->assertNotNull($value);
                $this->assertNotSame('', $value);
            }
            $key = json_encode([$row['SectionId'], $row['YearNumber']]);
            $this->assertArrayNotHasKey($key, $keys);
            $keys[$key] = true;
            $yearsById[$row['SectionId']][] = $row['YearNumber'];
            $this->assertSame(0.85, $row['CapacityFactor']);
            foreach ([1, 2, 3] as $phase) {
                foreach (['Kw', 'Kvar', 'Kwh'] as $quantity) {
                    $this->assertEquals(0, $row['Phase'.$phase.$quantity]);
                }
                $count = $row['Phase'.$phase.'Customers'];
                if ($count !== null) {
                    $this->assertEquals(floor($count), $count);
                }
            }
        }
        $this->assertCount(count($payload['tables']['Loads']) * 2, $payload['tables']['InstMymLoads']);
        foreach ($payload['tables']['Loads'] as $load) {
            $this->assertSame([0, 1], $yearsById[$load['SectionId']]);
        }
        $this->assertCount(count($payload['tables']['InstSection']) - 1, $payload['tables']['Loads']);
    }

    public function test_annual_loads_deduplicate_identical_source_rows_without_adding_demand(): void
    {
        $load = ['SectionId' => '00040701081026001.10', 'IsSpotLoad' => 0, 'Phase1Customers' => 1, 'Phase1Kva' => 2,
            'Phase1Kw' => ' ', 'Phase2Customers' => '', 'CapacityFactor' => null];
        $section = ['SectionId' => $load['SectionId'], 'Description' => 'Secondary Pole', 'ToNodeId' => '81026001.10'];
        $rows = (new InstMymLoadsGeneration)->apply([$load, $load], [$section]);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($load['SectionId'], $row['SectionId']);
            $this->assertSame(1, $row['Phase1Customers']);
            $this->assertSame(2, $row['Phase1Kva']);
            $this->assertSame(0, $row['Phase1Kw']);
            $this->assertSame(0, $row['Phase2Customers']);
            $this->assertSame(0, $row['CapacityFactor']);
        }
        $this->expectException(ValidationException::class);
        (new InstMymLoadsGeneration)->apply([$load, array_replace($load, ['IsSpotLoad' => 1])], [$section]);
    }

    public function test_annual_loads_preserve_fractional_balanced_pv_customer_values(): void
    {
        $rows = (new InstMymLoadsGeneration)->apply(
            [['SectionId' => '00040701081026001', 'Phase1Customers' => 1 / 3]],
            [['SectionId' => '00040701081026001']]
        );
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1 / 3, $rows[1]['Phase1Customers'], 1e-8);
    }

    public function test_connection_load_reuses_its_text_section_id_and_current_values_in_both_years(): void
    {
        $id = '00040701081026001.10';
        $load = ['SectionId' => $id, 'IsSpotLoad' => 0, 'CapacityFactor' => 0.9];
        foreach ([1, 2, 3] as $phase) {
            foreach (['Customers', 'Kw', 'Kvar', 'Kva', 'Kwh'] as $field) {
                $load['Phase'.$phase.$field] = $phase * 10;
            }
        }
        $section = ['SectionId' => $id, 'Description' => 'Secondary Pole', 'ToNodeId' => '81026001.10'];
        $rows = (new InstMymLoadsGeneration)->apply([$load], [$section]);
        $this->assertCount(2, $rows);
        foreach ([0, 1] as $year) {
            $this->assertSame($id, $rows[$year]['SectionId']);
            $this->assertSame($year, $rows[$year]['YearNumber']);
            foreach ($load as $field => $value) {
                if ($field !== 'SectionId') {
                    $this->assertSame($value, $rows[$year][$field]);
                }
            }
        }
    }

    public function test_all_phase_single_phase_customers_rotate_and_rb_allocation_never_uses_y(): void
    {
        $s = self::snapshot();
        $s['transformers'][0]['entry_rows'][1]['consumers'] = ['rs' => 3];
        $s['transformers'][0]['entry_rows'][1]['consumer_phases'] = ['rs' => ['R' => 3]];
        $s['transformers'][0]['sections'][1]['phases'] = ['R', 'B', 'N'];
        $s['transformers'][0]['sections'][1]['conductors']['Y'] = '';
        $byId = array_column($this->build($s)['tables']['InstSection'], null, 'SectionId');
        foreach (range(1, 3) as $i) {
            $this->assertSame(['RN', 'YN', 'BN'][$i - 1], $byId['00040701081026001.'.$i]['SectionPhases']);
        }
        foreach (range(1, 14) as $i) {
            $this->assertSame($i % 2 === 1 ? 'RN' : 'BN', $byId['00040701081026002.'.$i]['SectionPhases']);
        }
    }

    public function test_later_tables_keep_all_business_values_and_only_section_references_change(): void
    {
        $s = self::snapshot();
        $new = $this->build($s);
        foreach ($s['transformers'][0]['entry_rows'] as &$row) {
            unset($row['composite_identifier']);
        }
        unset($row);
        $old = $this->build($s);
        foreach (['InstPrimaryTransformers', 'InstMeterPoints', 'InstDGens', 'InstMymDGens'] as $table) {
            $strip = fn ($rows) => array_map(function ($row) use ($table) {
                unset($row['SectionId']);
                if ($table === 'Loads') {
                    unset($row['Description']);
                }

                return $row;
            }, $rows);
            $this->assertSame($strip($old['tables'][$table]), $strip($new['tables'][$table]), $table);
        }
        $sections = array_column($new['tables']['InstSection'], null, 'SectionId');
        $this->assertSame('Transformer', $sections[$new['tables']['InstPrimaryTransformers'][0]['SectionId']]['Description']);
        $this->assertSame($new['tables']['InstPrimaryTransformers'][0]['SectionId'], $new['tables']['InstMeterPoints'][0]['SectionId']);
        $this->assertSame('Transformer', $sections[$new['tables']['InstMeterPoints'][0]['SectionId']]['Description']);
    }

    public function test_workbook_conductors_do_not_replace_a_different_surveyed_pole_conductor(): void
    {
        $s = self::snapshot();
        $s['transformers'][0]['sections'][1]['conductors'] = array_fill_keys(['R', 'Y', 'B', 'N'], 'GNAT');
        $s['transformers'][0]['sections'][1]['consumers'][] = ['category' => 'rl', 'count' => '0'];
        $rows = array_column($this->build($s)['tables']['InstSection'], null, 'SectionId');
        $this->assertSame('500 MCM', $rows['00040711131222104']['PhaseConductorId']);
        $this->assertSame('4/C 07/.052', $rows['00040711131222104.1']['PhaseConductorId']);
        $this->assertSame('GNAT', $rows['00040701081026002']['PhaseConductorId']);
        $this->assertSame('PVC 07/0.052', $rows['00040701081026002.1']['PhaseConductorId']);
    }

    public function test_optional_transformer_section_pointers_are_remapped_without_changing_placeholders(): void
    {
        $s = self::snapshot();
        $legacy = $s;
        foreach ($legacy['transformers'][0]['entry_rows'] as &$entry) {
            unset($entry['composite_identifier']);
        }
        unset($entry);
        $old = $this->build($legacy);
        $reference = collect($old['provenance'])->firstWhere('survey_section_id', 2)['section_id'];
        $s['configuration']['settings']['mapping']['table_values']['InstPrimaryTransformers'] += [
            'TertIncidentSectId1' => $reference, 'TertIncidentSectId2' => '*'];
        $row = $this->build($s)['tables']['InstPrimaryTransformers'][0];
        $this->assertSame('00040701081026002', $row['TertIncidentSectId1']);
        $this->assertSame('*', $row['TertIncidentSectId2']);
    }

    public function test_missing_height_elevation_or_phase_evidence_blocks_with_record_context(): void
    {
        foreach (['height', 'elevation', 'phases'] as $case) {
            $s = self::snapshot();
            if ($case === 'height') {
                $s['transformers'][0]['entry_rows'][3]['pole_height'] = null;
            } elseif ($case === 'elevation') {
                $s['waypoints'][1]['elevation'] = null;
            } else {
                $s['transformers'][0]['sections'][1]['phases'] = [];
            }
            try {
                $this->build($s);
                $this->fail('Missing source evidence must be reported.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Transformer T-SYNTHETIC', $exception->getMessage());
            }
        }
    }

    public function test_normal_native_writer_reopens_new_sections_with_original_schema_and_valid_references(): void
    {
        $shell = AccessPowerShell::defaultPath();
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($shell)) {
            $this->markTestSkipped('Native Windows Access writer is unavailable.');
        }
        $probe = new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', '$ErrorActionPreference="Stop";$engine=New-Object -ComObject DAO.DBEngine.120']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Access DAO is unavailable.');
        }
        $directory = base_path('tmp/instsection-native-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);
        $template = resource_path('mdb/synergee-empty.mdb');
        $hash = hash_file('sha256', $template);
        try {
            foreach (['single' => [self::snapshot(), 21], 'expanded' => [self::mixedSnapshot(8, false), 30],
                'y-only-pv' => [self::yOnlyPvSnapshot(), 16],
                'one-conductor-three-phase' => [self::limitedPhaseSnapshot(['Y']), 22],
                'two-conductor-three-phase' => [self::limitedPhaseSnapshot(['Y', 'B']), 22],
                'multiple-transformers' => [self::multipleTransformersSnapshot(), 42]] as $case => [$snapshot, $expectedSections]) {
                $payload = $this->build($snapshot, null);
                $input = $directory.'/'.$case.'.json';
                $output = $directory.'/'.$case.'.mdb';
                file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                $writer = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
                    base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $input, '-TemplatePath', $template, '-OutputPath', $output]);
                $writer->setTimeout(90);
                $writer->run();
                $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
                $result = json_decode(trim($writer->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                $readback = app(ReadbackVerifier::class)->verify($output, $payload, $result);
                $this->assertSame($expectedSections, $readback['counts']['InstSection']);
                $this->assertSame(51, $readback['schema_table_count']);
                $this->assertSame(42, $readback['schema_relationship_count']);
                $this->assertTrue($readback['mapped_values_verified']);
                $this->assertTrue($readback['references_verified']);
                $this->assertSame(count($payload['tables']['InstMymLoads']), $readback['counts']['InstMymLoads']);
                $this->assertNotEmpty($readback['counts']['InstMymLoads']);
                $sectionIds = array_fill_keys(array_column($payload['tables']['InstSection'], 'SectionId'), true);
                foreach ($payload['tables']['Loads'] as $load) {
                    $this->assertArrayHasKey($load['SectionId'], $sectionIds);
                    $this->assertSame('', $load['Description']);
                    $this->assertStringStartsNotWith('s', $load['SectionId']);
                }
                $this->assertSame($hash, hash_file('sha256', $template));
            }

            $snapshot = self::snapshot();
            $mapping = &$snapshot['configuration']['settings']['mapping'];
            $mapping['version'] = ExportPayloadBuilder::EXTENDED_VERSION;
            $mapping['table_values'] += ['InstMeterPoints' => [], 'InstDGens' => [], 'InstMymDGens' => []];
            unset($mapping);
            $payload = $this->build($snapshot, null);
            $input = $directory.'/meter-demand.json';
            $output = $directory.'/meter-demand.mdb';
            file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $writer = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
                base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $input, '-TemplatePath', $template, '-OutputPath', $output]);
            $writer->setTimeout(90);
            $writer->run();
            $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            $result = json_decode(trim($writer->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            $readback = app(ReadbackVerifier::class)->verify($output, $payload, $result);
            $this->assertSame(48, $readback['counts']['InstMeterDmd']);
            $this->assertSame(1, $readback['counts']['InstMeterPoints']);
            $this->assertTrue($readback['references_verified']);
            $this->assertSame($hash, hash_file('sha256', $template));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
