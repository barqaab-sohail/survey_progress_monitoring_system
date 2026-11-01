<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\ModelReadiness;
use App\Services\Mdb\ProjectionService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbExportPayloadTest extends TestCase
{
    /** Entirely synthetic reviewed engineering fixture; values are not production defaults. */
    private function fixture(): array
    {
        $sectionValues = ['ConfigurationId' => 'reviewed-config', 'SectionPhases' => 'RYBN', 'PhaseConductorId' => 'R-wire', 'PhaseConductor2Id' => 'Y-wire', 'PhaseConductor3Id' => 'B-wire', 'NeutralConductorId' => 'N-wire', 'SectionLength_MUL' => 0];
        $settings = ['frequency_hz' => 50, 'nominal_voltage_kv' => 11, 'mapping' => [
            'version' => ExportPayloadBuilder::VERSION, 'reviewed_by' => 2, 'reviewed_at' => '2026-10-09T00:00:00Z', 'consolidated_supported' => false,
            'table_values' => ['SAI_Control' => ['Frequency' => 50, 'LengthUnits' => 'Metric', 'Product' => 'SynerGEE Electric 5.0.0.324', 'ProjectionFile' => 'approved-crs'], 'Node' => [], 'InstSection' => ['ConfigurationId' => 'reviewed-config'], 'InstFeeders' => ['SubstationId' => 'approved-grid', 'NominalKvll' => 11], 'InstPrimaryTransformers' => [], 'Loads' => []],
            'networks' => ['T-fixture' => ['source_nodes' => [['key' => 'feeder-source', 'waypoint_id' => 1], ['key' => 'transformer-hv', 'waypoint_id' => 1]], 'source_sections' => [['key' => 'supply', 'from' => 'feeder-source', 'to' => 'transformer-hv', 'fields' => $sectionValues], ['key' => 'transformer', 'from' => 'transformer-hv', 'to' => 'wp:1', 'fields' => $sectionValues]], 'feeder_node' => 'feeder-source', 'transformer_section' => 'transformer', 'transformer_fields' => ['TransformerType' => 'reviewed-100', 'SpecNomKv' => 11, 'UseInstanceImpedance' => 0]]],
        ]];
        $waypoints = array_map(fn ($id) => ['id' => $id, 'name' => str_pad((string) $id, 3, '0', STR_PAD_LEFT), 'latitude' => 30 + $id / 10000, 'longitude' => 71, 'correction' => null], [1, 2, 3]);
        $load = ['category' => 'RS', 'count' => 3, 'demand' => ['approved_by' => 2, 'method' => 'measured fixture', 'evidence' => 'fixture only', 'phase_values' => array_fill_keys(['R', 'Y', 'B'], ['customers' => 1, 'kw' => 1, 'kvar' => 0, 'kva' => 1])]];
        $sections = array_map(fn ($id) => ['id' => $id, 'start_waypoint_id' => 1, 'end_waypoint_id' => $id + 1, 'phases' => ['R', 'Y', 'B', 'N'], 'conductors' => ['R' => 'R-wire', 'Y' => 'Y-wire', 'B' => 'B-wire', 'N' => 'N-wire'], 'equipment_ref' => 'reviewed-config', 'geometry' => [], 'measured_length_m' => null, 'source_page' => 1, 'source_row' => $id, 'source_pdf_id' => 1, 'consumers' => $id === 1 ? [$load] : [['category' => 'RS', 'count' => 0, 'demand' => null]]], [1, 2]);
        $template = new Template(['code' => 'fixture', 'version' => 'v1', 'sha256' => str_repeat('a', 64), 'approved_by' => 2, 'approved_at' => '2026-10-09', 'active' => true, 'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'metadata' => ['equipment_references' => ['conductors' => ['R-wire', 'Y-wire', 'B-wire', 'N-wire'], 'transformers' => ['reviewed-100'], 'configurations' => ['reviewed-config']]]]);
        $snapshot = ['configuration' => ['epsg' => 32643, 'approved_by' => 2, 'approved_at' => '2026-10-09', 'settings' => $settings], 'template' => ['sha256' => $template->sha256, 'version' => $template->version], 'waypoints' => $waypoints, 'transformers' => [['id' => 1, 'code' => 'T-fixture', 'header' => ['substation_name' => 'approved-grid'], 'source_waypoint_id' => 1, 'sections' => $sections, 'pv_records' => []]]];
        $revision = new Revision(['batch_id' => 7, 'number' => 1, 'snapshot' => $snapshot, 'sha256' => str_repeat('b', 64)]);

        return [$revision, $template];
    }

    private function builder(): ExportPayloadBuilder
    {
        $projection = $this->mock(ProjectionService::class);
        $projection->shouldReceive('project')->andReturnUsing(fn ($lat, $lon) => ['x' => $lon * 1000, 'y' => $lat * 1000]);
        $projection->shouldReceive('length')->andReturn(15.0);

        return new ExportPayloadBuilder($projection);
    }

    public function test_feeder_uses_its_node_and_verbatim_pdf_header_name_without_changing_other_columns(): void
    {
        foreach ([false, true] as $simple) {
            [$revision, $template] = $this->fixture();
            $snapshot = $revision->snapshot;
            $snapshot['configuration']['settings']['simple_mdb_generation'] = $simple;
            $base = json_decode(file_get_contents(base_path('tests/Fixtures/Mdb/noor-pur-writer.json')), true, 512, JSON_THROW_ON_ERROR)['tables']['InstFeeders'][0];
            $snapshot['configuration']['settings']['mapping']['table_values']['InstFeeders'] = $base;
            $overrides = ['SubstationId' => 'Configured code must not win', 'FeederId' => 'Unrelated feeder', 'Note_' => 'Keep network note', 'DefaultSelect' => 0, 'ByPhVoltLevelPh1' => 97.5];
            $snapshot['configuration']['settings']['mapping']['networks']['T-fixture']['feeder_fields'] = $overrides;
            $name = '132 KVA Abotabad / East';
            $snapshot['transformers'][0]['header']['substation_name'] = $name;
            $snapshot['transformers'][0]['header']['substation_identifier'] = '407';
            $revision->snapshot = $snapshot;
            $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
            $feeder = $payload['tables']['InstFeeders'][0];
            $sourceNode = collect($payload['tables']['Node'])->firstWhere('Description', 'feeder-source');
            $this->assertSame($sourceNode['NodeId'], $feeder['FeederId']);
            $this->assertSame($name, $feeder['SubstationId']);
            $this->assertSame(array_replace($base, $overrides, ['FeederId' => $sourceNode['NodeId'], 'SubstationId' => $name]), $feeder);
            $this->assertCount(40, $feeder);
            $this->assertSame($snapshot, $revision->snapshot);
            $this->assertSame($snapshot['configuration']['settings'], $payload['settings']['electrical']);
            foreach ($payload['tables']['InstSection'] as $section) {
                $this->assertSame($feeder['FeederId'], $section['FeederId']);
            }
        }
    }

    public function test_missing_or_overlong_pdf_substation_name_cannot_fall_back_to_a_code_or_default(): void
    {
        foreach ([null, '', '   ', str_repeat('x', 33)] as $name) {
            [$revision, $template] = $this->fixture();
            $snapshot = $revision->snapshot;
            $snapshot['transformers'][0]['header'] = ['substation_name' => $name, 'substation_identifier' => '407'];
            $revision->snapshot = $snapshot;
            try {
                $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
                $this->fail('A source PDF substation name must be supplied without truncation.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Transformer T-fixture: verify the source PDF substation name', $exception->getMessage());
            }
        }
    }

    public function test_simple_export_keeps_configured_type_and_construction_and_positions_logical_terminal(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $snapshot['configuration']['settings']['engineering'] = ['configuration_id' => 'reviewed-default', 'transformer_types' => ['100' => 'reviewed-100']];
        $snapshot['configuration']['settings']['mapping']['networks'] = [];
        $snapshot['configuration']['settings']['mapping']['table_values']['InstPrimaryTransformers']['ConnectedPhases'] = 'RYB';
        $snapshot['transformers'][0]['capacity_kva'] = 100;
        $snapshot['transformers'][0]['sections'][0]['equipment_ref'] = null;
        $revision->snapshot = $snapshot;
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        foreach ($payload['tables']['Node'] as $node) {
            $this->assertIsNumeric($node['X']);
            $this->assertIsNumeric($node['Y']);
        }
        $this->assertSame(0.0, $payload['tables']['InstSection'][0]['SectionLength_MUL']);
        $this->assertSame('RYB', $payload['tables']['InstSection'][0]['SectionPhases']);
        $this->assertSame('reviewed-default', $payload['tables']['InstSection'][1]['ConfigurationId']);
        $this->assertSame('reviewed-config', $payload['tables']['InstSection'][2]['ConfigurationId']);
        $this->assertSame('reviewed-100', $payload['tables']['InstPrimaryTransformers'][0]['TransformerType']);
    }

    public function test_generated_mdb_coordinates_share_only_the_first_pairs_e_evidence(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        foreach ($snapshot['waypoints'] as &$point) {
            $point['source_file_id'] = 1;
            $point['elevation'] = 100 + $point['id'];
        }
        unset($point);
        $first = $snapshot['transformers'][0]['sections'][0];
        $first = array_replace($first, ['id' => 99, 'start_reference' => '999', 'end_reference' => '001', 'start_waypoint_id' => null, 'end_waypoint_id' => 1]);
        array_unshift($snapshot['transformers'][0]['sections'], $first);
        $snapshot['transformers'][0]['entry_rows'] = [
            ['id' => 11, 'pair_number' => 1, 'section_id' => 99, 'designation' => 'S', 'waypoint_reference' => '999', 'gpx_source_id' => null],
            ['id' => 12, 'pair_number' => 1, 'section_id' => 99, 'designation' => 'E', 'waypoint_reference' => '001', 'gpx_source_id' => 1],
        ];
        $revision->snapshot = $snapshot;
        $projection = new class extends ProjectionService
        {
            public function projectMany(array $points, int $epsg): array
            {
                return array_map(fn ($p) => ['x' => $p['longitude'] * 100000, 'y' => $p['latitude'] * 100000], $points);
            }
        };
        $payload = (new ExportPayloadBuilder($projection))->build($revision, $template, 1, str_repeat('c', 64));
        $nodes = array_column($payload['tables']['Node'], null, 'Description');
        $this->assertNotSame($nodes['999']['NodeId'], $nodes['001']['NodeId']);
        foreach (['X', 'Y'] as $axis) {
            $this->assertSame($nodes['001'][$axis], $nodes['999'][$axis]);
        }
        $this->assertNotSame($nodes['001']['Y'], $nodes['002']['Y']);
        $this->assertNotSame($nodes['002']['Y'], $nodes['003']['Y']);
        $this->assertSame(0.0, $payload['tables']['InstSection'][2]['SectionLength_MUL']);
        $this->assertGreaterThan(0, $payload['tables']['InstSection'][3]['SectionLength_MUL']);
        $this->assertGreaterThan(0, $payload['tables']['InstSection'][4]['SectionLength_MUL']);
        $this->assertCount(3, $payload['provenance']);
        $this->assertSame($snapshot, $revision->snapshot);
    }

    public function test_export_uses_elevation_adjusted_lengths_with_flagged_fallback_and_measured_override(): void
    {
        [$revision, $template] = $this->fixture();
        $projection = new class extends ProjectionService
        {
            public function projectMany(array $points, int $epsg): array
            {
                return array_map(fn ($point) => ['x' => $point['longitude'] * 100000, 'y' => $point['latitude'] * 100000], $points);
            }
        };
        $builder = new ExportPayloadBuilder($projection);
        $baseline = $builder->build($revision, $template, 1, str_repeat('c', 64));
        $snapshot = $revision->snapshot;
        $snapshot['waypoints'][0]['elevation'] = 0;
        $snapshot['waypoints'][1]['elevation'] = 10;
        $revision->snapshot = $snapshot;
        $payload = $builder->build($revision, $template, 1, str_repeat('d', 64));
        $this->assertEqualsWithDelta(hypot(10, 10), $payload['tables']['InstSection'][2]['SectionLength_MUL'], 0.001);
        $this->assertSame('elevation_adjusted', $payload['provenance'][0]['elevation_status']);
        $this->assertEqualsWithDelta(20, $payload['tables']['InstSection'][3]['SectionLength_MUL'], 0.001);
        $this->assertSame('horizontal_missing_elevation', $payload['provenance'][1]['elevation_status']);
        $this->assertStringContainsString('elevation is missing', implode(' ', $payload['engineering_omissions']));
        $this->assertSame(array_keys($baseline['tables']['InstSection'][2]), array_keys($payload['tables']['InstSection'][2]));
        $this->assertSame(ProjectionService::LENGTH_METHOD, $payload['settings']['length_calculation']);

        $snapshot['configuration']['settings']['engineering'] = ['sag_allowance_percent' => 2.5,
            'transformer_types' => ['100' => 'reviewed-100']];
        $snapshot['transformers'][0]['capacity_kva'] = 100;
        $revision->snapshot = $snapshot;
        $withSag = $builder->build($revision, $template, 1, str_repeat('f', 64));
        $this->assertEqualsWithDelta(hypot(10, 10) * 1.025, $withSag['tables']['InstSection'][2]['SectionLength_MUL'], 0.001);
        $this->assertEqualsWithDelta(20 * 1.025, $withSag['tables']['InstSection'][3]['SectionLength_MUL'], 0.001);
        $this->assertSame('applied', $withSag['provenance'][0]['sag_status']);
        $this->assertSame(2.5, $withSag['provenance'][0]['sag_allowance_percent']);
        $this->assertEqualsWithDelta(hypot(10, 10) * 0.025, $withSag['provenance'][0]['sag_addition_m'], 0.001);
        $this->assertSame($payload['tables']['InstSection'][0]['SectionLength_MUL'], $withSag['tables']['InstSection'][0]['SectionLength_MUL']);

        $snapshot['transformers'][0]['sections'][0] = array_replace($snapshot['transformers'][0]['sections'][0],
            ['measured_length_m' => 16, 'measured_length_reason' => 'Verified measurement', 'length_approved_by' => 2]);
        $revision->snapshot = $snapshot;
        $measured = $builder->build($revision, $template, 1, str_repeat('e', 64));
        $this->assertSame(16.0, $measured['tables']['InstSection'][2]['SectionLength_MUL']);
        $this->assertSame('approved_measured', $measured['provenance'][0]['length_source']);
        $this->assertSame('measured_override', $measured['provenance'][0]['elevation_status']);
        $this->assertSame('measured_override', $measured['provenance'][0]['sag_status']);
        $this->assertSame(0.0, $measured['provenance'][0]['sag_addition_m']);
        $this->assertNull($measured['provenance'][0]['sag_allowance_percent']);
    }

    public function test_branch_endpoints_distinct_conductors_and_approved_load_values_map_exactly(): void
    {
        [$revision, $template] = $this->fixture();
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $sections = $payload['tables']['InstSection'];
        $this->assertSame($sections[2]['FromNodeId'], $sections[3]['FromNodeId']);
        $this->assertNotSame($sections[2]['ToNodeId'], $sections[3]['ToNodeId']);
        $this->assertSame('R-wire', $sections[2]['PhaseConductorId']);
        $this->assertSame('Y-wire', $sections[2]['PhaseConductor2Id']);
        $this->assertSame('B-wire', $sections[2]['PhaseConductor3Id']);
        $this->assertSame('N-wire', $sections[2]['NeutralConductorId']);
        $this->assertCount(count($sections) - 1, $payload['tables']['Loads']);
        $this->assertSame(1.0, $payload['tables']['Loads'][0]['Phase1Kva']);
        $this->assertSame('endpoint_estimate', $payload['provenance'][0]['length_source']);
        $this->assertSame($payload['tables'], $this->builder()->build($revision, $template, 1, str_repeat('d', 64))['tables']);
    }

    public function test_approved_pair_sections_combine_categories_and_preserve_user_rates(): void
    {
        $pairs = json_decode(file_get_contents(__DIR__.'/../Fixtures/approved-section-load-pairs.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($pairs as $pair) {
            [$revision, $template] = $this->fixture();
            $snapshot = $revision->snapshot;
            $snapshot['configuration']['revision'] = 1;
            $snapshot['configuration']['load_assumptions'] = ['mode' => 'category_rates', 'evidence' => 'Approved paired MDB phase allocation; user rates 2/5 kVA'];
            $snapshot['configuration']['settings']['engineering'] = ['configuration_id' => 'reviewed-config', 'transformer_types' => ['100' => 'reviewed-100']];
            $snapshot['transformers'][0]['capacity_kva'] = 100;
            $snapshot['waypoints'] = [$snapshot['waypoints'][0]];
            $sectionBase = $snapshot['transformers'][0]['sections'][0];
            $snapshot['transformers'][0]['sections'] = [];
            foreach ($pair['records'] as $i => $record) {
                $snapshot['waypoints'][] = ['id' => $i + 2, 'name' => (string) ($i + 2), 'latitude' => 30 + ($i + 2) / 10000, 'longitude' => 71];
                $snapshot['transformers'][0]['sections'][] = array_replace($sectionBase, [
                    'id' => $i + 1, 'end_waypoint_id' => $i + 2,
                    'phases' => str_split(str_replace(' ', '', $record['phases'])),
                    'conductors' => array_intersect_key($sectionBase['conductors'], array_flip(str_split($record['phases']))),
                    'consumers' => [['category' => 'rs', 'count' => $record['single_phase']], ['category' => 'rl', 'count' => $record['three_phase']]],
                ]);
            }
            $revision->snapshot = app(EngineeringConfiguration::class)->apply($snapshot);
            $payload = $this->builder()->build($revision, $template, 1, str_repeat('f', 64));
            $this->assertCount(count($pair['records']) + 1, $payload['tables']['Loads']);
            $totalKva = $totalCustomers = 0;
            foreach (array_slice($payload['tables']['Loads'], 0, count($pair['records'])) as $i => $load) {
                $record = $pair['records'][$i];
                $active = count(array_filter(str_split($record['phases']), fn ($phase) => in_array($phase, ['R', 'Y', 'B'], true)));
                foreach (['R', 'Y', 'B'] as $j => $phase) {
                    if (! str_contains($record['phases'], $phase)) {
                        $this->assertNull($load['Phase'.($j + 1).'Customers'], json_encode($record));
                        $this->assertNull($load['Phase'.($j + 1).'Kva']);

                        continue;
                    }
                    $customers = $load['Phase'.($j + 1).'Customers'];
                    $kva = $load['Phase'.($j + 1).'Kva'];
                    $this->assertEquals(floor($customers), $customers);
                    $this->assertEquals(floor($kva), $kva);
                    $this->assertSame(0, $load['Phase'.($j + 1).'Kw']);
                    $this->assertSame(0, $load['Phase'.($j + 1).'Kvar']);
                    $totalCustomers += $customers;
                    $totalKva += $kva;
                }
            }
            $this->assertEqualsWithDelta($pair['single_phase'] + $pair['three_phase'], $totalCustomers, 1e-8);
            $this->assertEqualsWithDelta($pair['single_phase'] * 2 + $pair['three_phase'] * 5, $totalKva, 1e-8);
        }
    }

    public function test_recorded_allocations_reach_combined_load_rows_in_full_and_simple_modes(): void
    {
        foreach ([false, true] as $simple) {
            [$revision, $template] = $this->fixture();
            $snapshot = $revision->snapshot;
            $snapshot['configuration']['revision'] = 1;
            $snapshot['configuration']['settings']['simple_mdb_generation'] = $simple;
            $snapshot['configuration']['settings']['engineering'] = ['configuration_id' => 'reviewed-config', 'transformer_types' => ['100' => 'reviewed-100']];
            $snapshot['configuration']['load_assumptions'] = ['mode' => 'category_rates', 'single_phase_allocation' => 'recorded_or_equal', 'evidence' => 'Verified PDF phases'];
            $snapshot['transformers'][0]['capacity_kva'] = 100;
            $snapshot['transformers'][0]['sections'][0]['consumers'] = [['category' => 'rs', 'count' => 10]];
            $snapshot['transformers'][0]['sections'][0]['original_entry']['entry_rows'] = [
                ['consumers' => ['rs' => 10], 'consumer_phases' => ['rs' => ['R' => 8, 'Y' => 1, 'B' => 1]]],
            ];
            $snapshot['transformers'][0]['sections'][1]['consumers'] = [];
            $revision->snapshot = app(EngineeringConfiguration::class)->apply($snapshot);
            $payload = $this->builder()->build($revision, $template, 1, str_repeat('a', 64));
            $this->assertCount(count($payload['tables']['InstSection']) - 1, $payload['tables']['Loads']);
            $load = $payload['tables']['Loads'][0];
            $this->assertSame([8.0, 1.0, 1.0], [$load['Phase1Customers'], $load['Phase2Customers'], $load['Phase3Customers']]);
            $this->assertSame([16.0, 2.0, 2.0], [$load['Phase1Kva'], $load['Phase2Kva'], $load['Phase3Kva']]);
            $this->assertSame('recorded', $payload['load_provenance'][0]['demand']['allocation_basis']);
        }
    }

    public function test_simple_v2_meter_is_associated_with_a_separate_logical_secondary_link(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $mapping = &$snapshot['configuration']['settings']['mapping'];
        $mapping['version'] = ExportPayloadBuilder::EXTENDED_VERSION;
        $mapping['networks'] = [];
        $mapping['table_values']['InstPrimaryTransformers']['ConnectedPhases'] = 'RYB';
        $mapping['table_values']['InstMeterPoints'] = ['Phase1Value' => 999, 'Enabled' => 1];
        $revision->snapshot = $snapshot;
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('a', 64));
        $sections = array_column($payload['tables']['InstSection'], null, 'SectionId');
        $primary = $sections[$payload['tables']['InstPrimaryTransformers'][0]['SectionId']];
        $this->assertCount(1, $payload['tables']['InstMeterPoints']);
        $meter = $payload['tables']['InstMeterPoints'][0];
        $this->assertSame($primary['SectionId'], $meter['SectionId']);
        $this->assertNotSame('00040701031222104.1', $meter['SectionId']);
        $schema = json_decode(file_get_contents(base_path('docs/mdb/sample-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $meterSchema = collect($schema['tables'])->firstWhere('name', 'InstMeterPoints');
        $this->assertSame(array_column($meterSchema['fields'], 'name'), array_keys($meter));
        $this->assertSame([
            'SectionId' => $primary['SectionId'], 'Phase1Value' => 10.3, 'Phase2Value' => 10.3, 'Phase3Value' => 10.3,
            'Phase1Value2' => 85, 'Phase2Value2' => 85, 'Phase3Value2' => 85, 'CalibSAIFI' => 1,
            'CalibSAIDI_Hr' => 4, 'CalibMAIFI' => 3, 'ContinuousCurrentRating' => 0, 'EmergencyCurrentRating' => 0,
            'MeterIsOpen' => 0, 'UseDemandAsLoad' => 0, 'Description' => '132 KVA Abotabad', 'Enabled' => 1,
            'EnableReliCalibration' => 0, 'NearFromNode' => 1, 'Type' => 'I', 'OverriddenUpstream' => 1,
            'EnergizeYear' => 0, 'RetireYear' => 100, 'Note_' => null, 'UseGrowthCurve' => 0, 'GrowthCurveId' => null,
            'UseDefDistCustZone' => 0, 'DefDistCustZone' => 'Unknown', 'UseDefSpotCustZone' => 0,
            'DefSpotCustZone' => 'Unknown', 'IsColorBy' => 0, 'RGBColor' => '125,125', 'LocationLink' => '*',
            'AMSLink' => null, 'LockFedLoad' => 0,
        ], $meter);
        $secondary = collect($sections)->first(fn ($section) => $section['SectionId'] !== $primary['SectionId']
            && $section['FromNodeId'] === $primary['ToNodeId'] && $section['SectionLength_MUL'] === 0.0);
        $this->assertNotNull($secondary);
        $this->assertSame($primary['ToNodeId'], $secondary['FromNodeId']);
        $this->assertSame(0.0, $secondary['SectionLength_MUL']);
        $secondaryLoad = collect($payload['tables']['Loads'])->firstWhere('SectionId', $secondary['SectionId']);
        $this->assertSame(0.0, $secondaryLoad['Phase1Kva']);
        $this->assertSame(0, $secondaryLoad['Phase1Kw']);
    }

    public function test_combining_categories_keeps_unknown_demand_unknown(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $snapshot['transformers'][0]['sections'][0]['consumers'][] = ['category' => 'sc', 'count' => 1, 'demand' => null];
        $revision->snapshot = $snapshot;
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('a', 64));
        $this->assertCount(count($payload['tables']['InstSection']) - 1, $payload['tables']['Loads']);
        $this->assertNull($payload['tables']['Loads'][0]['Phase1Kva']);
        $this->assertEquals(4, array_sum(array_map(fn ($p) => $payload['tables']['Loads'][0]['Phase'.$p.'Customers'], [1, 2, 3])));
        $this->assertCount(2, $payload['load_provenance']);
    }

    public function test_central_configuration_applies_library_type_impedance_mode_and_category_demand_to_export(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['revision'] = 4;
        $snapshot['configuration']['settings']['engineering'] = ['configuration_id' => 'reviewed-config', 'transformer_types' => ['100' => 'reviewed-100']];
        $snapshot['configuration']['settings']['mapping']['table_values']['InstPrimaryTransformers'] = ['UseInstanceImpedance' => 0, 'SpecNomKv' => 0.4];
        $snapshot['configuration']['load_assumptions'] = ['mode' => 'category_rates', 'method' => 'Approved synthetic rates', 'evidence' => 'fixture', 'rates' => ['rs' => ['kva' => 3, 'power_factor' => 0.8]]];
        $snapshot['transformers'][0]['capacity_kva'] = 100;
        foreach ($snapshot['transformers'][0]['sections'] as &$section) {
            $section['consumers'][0]['category'] = 'rs';
        }
        unset($section);
        $snapshot['configuration']['settings']['mapping']['networks']['T-fixture']['transformer_fields'] = ['TransformerType' => 'local-wrong-type', 'UseInstanceImpedance' => 1, 'SpecNomKv' => 4.16];
        $revision->snapshot = app(EngineeringConfiguration::class)->apply($snapshot);
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $transformer = $payload['tables']['InstPrimaryTransformers'][0];
        $this->assertSame('reviewed-100', $transformer['TransformerType']);
        $this->assertSame(0, $transformer['UseInstanceImpedance']);
        $this->assertSame(0.4, $transformer['SpecNomKv']);
        $load = $payload['tables']['Loads'][0];
        $this->assertSame(0, $load['Phase1Kw']);
        $this->assertSame(0, $load['Phase1Kvar']);
        $this->assertSame(2.0, $load['Phase1Kva']);
    }

    public function test_both_phase_types_export_direct_kva_without_power_factor_in_full_and_simple_modes(): void
    {
        foreach ([false, true] as $simple) {
            [$revision, $template] = $this->fixture();
            $snapshot = $revision->snapshot;
            $snapshot['configuration']['revision'] = 1;
            $snapshot['configuration']['settings']['simple_mdb_generation'] = $simple;
            $snapshot['configuration']['settings']['engineering'] = ['configuration_id' => 'reviewed-config', 'transformer_types' => ['100' => 'reviewed-100']];
            $snapshot['configuration']['load_assumptions'] = ['mode' => 'category_rates', 'evidence' => 'Department standard'];
            $snapshot['transformers'][0]['capacity_kva'] = 100;
            $snapshot['transformers'][0]['sections'][0]['consumers'] = [
                ['category' => 'rs', 'count' => 10], ['category' => 'rl', 'count' => 10],
            ];
            $snapshot['transformers'][0]['sections'][1]['consumers'] = [];
            $revision->snapshot = app(EngineeringConfiguration::class)->apply($snapshot);
            $payload = $this->builder()->build($revision, $template, 1, str_repeat('e', 64));
            $this->assertCount(count($payload['tables']['InstSection']) - 1, $payload['tables']['Loads']);
            $loadCheck = collect(app(ModelReadiness::class)->inspect($payload)['checks'])
                ->firstWhere('label', 'Load demand and customer allocation');
            $this->assertSame(count($payload['tables']['Loads']), $loadCheck['complete']);
            $this->assertSame(0, $loadCheck['missing']);
            foreach (array_slice($payload['tables']['Loads'], 0, 1) as $index => $load) {
                $total = 0;
                foreach ([1, 2, 3] as $phase) {
                    $total += $load['Phase'.$phase.'Kva'];
                    $this->assertSame(0, $load['Phase'.$phase.'Kw']);
                    $this->assertSame(0, $load['Phase'.$phase.'Kvar']);
                }
                $this->assertEqualsWithDelta(70, $total, 1e-8);
                $this->assertEqualsWithDelta(20, array_sum(array_map(fn ($phase) => $load['Phase'.$phase.'Customers'], [1, 2, 3])), 1e-8);
            }
        }
    }

    public function test_composite_node_ids_keep_repeated_gps_names_from_different_surveys_distinct(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['waypoints'][1]['name'] = '001';
        $snapshot['transformers'][0]['sections'][0]['original_entry'] = ['start_survey_identifier' => '01081022001', 'end_survey_identifier' => '01081026001'];
        $snapshot['transformers'][0]['sections'][1]['original_entry'] = ['start_survey_identifier' => '01081022001', 'end_survey_identifier' => '01081026003'];
        $revision->snapshot = $snapshot;
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $this->assertNotSame($payload['tables']['InstSection'][2]['FromNodeId'], $payload['tables']['InstSection'][2]['ToNodeId']);
        $this->assertSame($payload['tables']['InstSection'][2]['FromNodeId'], $payload['tables']['InstSection'][3]['FromNodeId']);
        $descriptions = array_column($payload['tables']['Node'], 'Description');
        $this->assertContains('01081022001', $descriptions);
        $this->assertContains('01081026001', $descriptions);
        $this->assertContains('01081026003', $descriptions);
    }

    public function test_unreviewed_source_supply_mapping_cannot_invent_transformer_connectivity(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        unset($snapshot['configuration']['settings']['mapping']['networks']);
        $revision->snapshot = $snapshot;
        $this->expectException(ValidationException::class);
        $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
    }

    public function test_active_pv_blocks_export_without_verifier_approved_exclusion(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['transformers'][0]['pv_records'] = [['installed_capacity_kw' => 4]];
        $snapshot['configuration']['settings']['mapping']['pv_exclusion'] = ['reason' => 'exclude fixture', 'reviewed_by' => 99, 'reviewed_at' => '2026-10-09'];
        $revision->snapshot = $snapshot;
        $this->expectException(ValidationException::class);
        $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
    }

    public function test_missing_active_load_phase_blocks_instead_of_allocating_customer_counts(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        unset($snapshot['transformers'][0]['sections'][0]['consumers'][0]['demand']['phase_values']['Y']);
        $revision->snapshot = $snapshot;
        $this->expectException(ValidationException::class);
        $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
    }

    public function test_partial_phases_keep_fixed_slots_and_blue_loads_keep_phase_three_with_signed_kvar(): void
    {
        foreach ([['R', 'N'], ['R', 'B', 'N'], ['B', 'N']] as $phases) {
            [$revision, $template] = $this->fixture();
            $snapshot = $revision->snapshot;
            $section = &$snapshot['transformers'][0]['sections'][0];
            $section['phases'] = $phases;
            $section['conductors'] = array_intersect_key($section['conductors'], array_flip($phases));
            $loadPhase = in_array('B', $phases, true) ? 'B' : 'R';
            $section['consumers'][0]['demand']['phase_values'] = [];
            foreach (array_intersect($phases, ['R', 'Y', 'B']) as $phase) {
                $section['consumers'][0]['demand']['phase_values'][$phase] = ['customers' => $phase === $loadPhase ? 3 : 0, 'kw' => $phase === $loadPhase ? 1 : 0, 'kvar' => $phase === $loadPhase ? -0.5 : 0, 'kva' => $phase === $loadPhase ? sqrt(1.25) : 0];
            }
            $source = &$snapshot['configuration']['settings']['mapping']['networks']['T-fixture']['source_sections'][0]['fields'];
            $source['SectionPhases'] = 'R BN';
            $source['PhaseConductor2Id'] = null;
            $revision->snapshot = $snapshot;
            $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
            $expectedCode = match ($phases) {
                ['R', 'N'] => 'R  N', ['R', 'B', 'N'] => 'R BN', ['B', 'N'] => '  BN'
            };
            $mapped = $payload['tables']['InstSection'][2];
            $this->assertSame($expectedCode, $mapped['SectionPhases']);
            $this->assertSame('R BN', $payload['tables']['InstSection'][0]['SectionPhases']);
            $this->assertNull($mapped['PhaseConductor2Id']);
            $this->assertSame(in_array('B', $phases, true) ? 'B-wire' : null, $mapped['PhaseConductor3Id']);
            $load = $payload['tables']['Loads'][0];
            $loadIndex = $loadPhase === 'B' ? 3 : 1;
            $this->assertSame(3.0, $load['Phase'.$loadIndex.'Customers']);
            $this->assertSame(-0.5, $load['Phase'.$loadIndex.'Kvar']);
            $this->assertNull($load['Phase2Customers']);
            $this->assertSame($phases, $revision->snapshot['transformers'][0]['sections'][0]['phases']);
            unset($section, $source);
        }
    }

    public function test_source_section_phase_letters_cannot_shift_between_fixed_slots(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['settings']['mapping']['networks']['T-fixture']['source_sections'][0]['fields']['SectionPhases'] = 'RBN';
        $revision->snapshot = $snapshot;
        $this->expectException(ValidationException::class);
        $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
    }

    public function test_v2_devices_resolve_current_section_ids_and_preserve_explicit_year_outputs(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $mapping = &$snapshot['configuration']['settings']['mapping'];
        $mapping['version'] = ExportPayloadBuilder::EXTENDED_VERSION;
        $mapping['table_values'] += ['InstMeterPoints' => [], 'InstDGens' => [], 'InstMymDGens' => []];
        $mapping['networks']['T-fixture']['meter_points'] = [['source_section' => 'supply', 'fields' => []]];
        $mapping['networks']['T-fixture']['generators'] = [[
            'pv_record_id' => 8, 'fields' => ['DGenOutputPct' => 100, 'DGenVoltSet' => 120],
            'years' => [['YearNumber' => 0, 'CapacityFactor' => 1, 'Phase1Kw' => 1, 'Phase2Kw' => 2, 'Phase3Kw' => 3, 'Phase1Kvar' => -0.5, 'Phase2Kvar' => 0, 'Phase3Kvar' => 0]],
        ]];
        $snapshot['transformers'][0]['pv_records'] = [['id' => 8, 'section_id' => 1, 'installed_capacity_kw' => 6]];
        $revision->snapshot = $snapshot;
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $this->assertSame(ExportPayloadBuilder::EXTENDED_VERSION, $payload['mapping_version']);
        $this->assertSame($payload['tables']['InstPrimaryTransformers'][0]['SectionId'], $payload['tables']['InstMeterPoints'][0]['SectionId']);
        $this->assertCount(48, $payload['tables']['InstMeterDmd']);
        $this->assertSame($payload['tables']['InstMeterPoints'][0]['SectionId'], $payload['tables']['InstMeterDmd'][0]['SectionId']);
        $this->assertSame($payload['tables']['InstMeterDmd'][0]['SectionId'], $payload['tables']['InstMeterDmd'][47]['SectionId']);
        $this->assertSame(['April', 'Minday', 95], [$payload['tables']['InstMeterDmd'][0]['Season'], $payload['tables']['InstMeterDmd'][0]['DayType'], $payload['tables']['InstMeterDmd'][0]['PctPf']]);
        $this->assertSame(['September', 'Weekend'], [$payload['tables']['InstMeterDmd'][47]['Season'], $payload['tables']['InstMeterDmd'][47]['DayType']]);
        $this->assertSame(100, $payload['tables']['InstMeterDmd'][0]['PctKwLoad_Hour01']);
        $this->assertSame(100, $payload['tables']['InstMeterDmd'][0]['PctKvarLoad_Hour24']);
        $this->assertSame(100, $payload['tables']['InstMeterDmd'][0]['PctKwDmd_Hour01']);
        $this->assertSame(100, $payload['tables']['InstMeterDmd'][0]['PctKvarDmd_Hour24']);
        $this->assertSame($payload['tables']['InstSection'][2]['SectionId'], $payload['tables']['InstDGens'][0]['SectionId']);
        $this->assertSame($payload['tables']['InstDGens'][0]['SectionId'], $payload['tables']['InstMymDGens'][0]['SectionId']);
        $this->assertSame(-0.5, $payload['tables']['InstMymDGens'][0]['Phase1Kvar']);
        $this->assertSame(60, $payload['tables']['SAI_Control'][0]['Frequency']);
        $this->assertSame('Synergi Electric 6.29.0', $payload['tables']['SAI_Control'][0]['Product']);
    }

    public function test_sai_control_matches_the_literal_reference_row_in_schema_order(): void
    {
        [$revision, $template] = $this->fixture();
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('a', 64));
        $row = $payload['tables']['SAI_Control'][0];
        $schema = json_decode(file_get_contents(base_path('docs/mdb/sample-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $table = collect($schema['tables'])->firstWhere('name', 'SAI_Control');

        $this->assertSame(array_column($table['fields'], 'name'), array_keys($row));
        $this->assertSame([
            'VoltageFactor' => 11, 'Frequency' => 60, 'LengthUnits' => 'Metric', 'Phase1' => 'R', 'Phase2' => 'Y',
            'Phase3' => 'B', 'Product' => 'Synergi Electric 6.29.0', 'VoltageInPercent' => 0,
            'MYM_YearDesc_B' => '2015', 'MYM_YearDesc_1' => '2016', 'MYM_YearDesc_2' => '2017', 'MYM_YearDesc_3' => '2018',
            'MYM_YearDesc_4' => '2019', 'MYM_YearDesc_5' => '2020', 'MYM_YearDesc_6' => '2021', 'MYM_YearDesc_7' => '2022',
            'MYM_YearDesc_8' => '2023', 'MYM_YearDesc_9' => '2024', 'MYM_YearDesc10' => '2025',
            'ProjectionFile' => 'WGS 1984 UTM Zone 43N', 'ProjectionWKT' => null, 'PTSecondary' => 120,
        ], $row);
    }

    public function test_inst_mym_meter_has_exactly_the_reference_values_for_year_zero_and_one(): void
    {
        [$revision, $template] = $this->fixture();
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('a', 64));
        $rows = $payload['tables']['InstMymMeter'];
        $transformer = $payload['tables']['InstPrimaryTransformers'][0];
        $section = collect($payload['tables']['InstSection'])->firstWhere('SectionId', $transformer['SectionId']);
        $schema = json_decode(file_get_contents(base_path('docs/mdb/sample-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $meterSchema = collect($schema['tables'])->firstWhere('name', 'InstMymMeter');

        $this->assertSame($transformer['SectionId'], $section['SectionId']);
        $this->assertNotSame('00040701031222104.1', $transformer['SectionId']);
        $this->assertCount(2, $rows);
        $this->assertSame(array_column($meterSchema['fields'], 'name'), array_keys($rows[0]));
        $this->assertSame([
            'SectionId' => $transformer['SectionId'], 'Phase1Value' => 10.3, 'Phase2Value' => 10.3, 'Phase3Value' => 10.3,
            'Phase1Value2' => 85, 'Phase2Value2' => 85, 'Phase3Value2' => 85, 'CalibSAIFI' => 1,
            'CalibSAIDI_Hr' => 4, 'CalibMAIFI' => 3, 'ContinuousCurrentRating' => 0, 'EmergencyCurrentRating' => 0,
            'MeterIsOpen' => 0, 'UseDemandAsLoad' => 0, 'YearNumber' => 0,
        ], $rows[0]);
        $this->assertSame($rows[0]['SectionId'], $rows[1]['SectionId']);
        $this->assertSame(1, $rows[1]['YearNumber']);
        $this->assertSame(array_diff_key($rows[0], ['YearNumber' => true]), array_diff_key($rows[1], ['YearNumber' => true]));
        foreach ($rows as $row) {
            foreach ($row as $field => $value) {
                $this->assertNotNull($value, $field);
                $this->assertNotSame('', $value, $field);
            }
        }
    }

    public function test_v1_cannot_silently_drop_requested_devices(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['settings']['mapping']['networks']['T-fixture']['meter_points'] = [['source_section' => 'supply']];
        $revision->snapshot = $snapshot;
        $this->expectException(ValidationException::class);
        $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
    }

    public function test_simple_mode_keeps_unknown_engineering_blank_without_inventing_demand(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration'] = ['settings' => ['simple_mdb_generation' => true]];
        $snapshot['transformers'][0]['header']['substation_identifier'] = 'survey-grid';
        foreach ($snapshot['transformers'][0]['sections'] as &$section) {
            $section['phases'] = [];
            $section['conductors'] = [];
            $section['equipment_ref'] = null;
            foreach ($section['consumers'] as &$consumer) {
                $consumer['demand'] = null;
            }
            unset($consumer);
        }
        unset($section);
        $revision->snapshot = $snapshot;
        $template->approved_by = $template->approved_at = null;
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $this->assertTrue($payload['settings']['simple_mdb_generation']);
        $this->assertNull($payload['settings']['epsg']);
        $this->assertSame('Synergi Electric 6.29.0', $payload['tables']['SAI_Control'][0]['Product']);
        $this->assertNull($payload['tables']['SAI_Control'][0]['ProjectionWKT']);
        $this->assertArrayNotHasKey('TransformerType', $payload['tables']['InstPrimaryTransformers'][0]);
        foreach ($payload['tables']['Node'] as $node) {
            $this->assertNull($node['X']);
            $this->assertNull($node['Y']);
        }
        $surveySection = collect($payload['tables']['InstSection'])->firstWhere('SectionId', $payload['provenance'][0]['section_id']);
        $this->assertNull($surveySection['SectionLength_MUL']);
        $this->assertNull($surveySection['SectionPhases']);
        $this->assertNull($payload['tables']['Loads'][0]['Phase1Kva']);
        $this->assertNull($payload['tables']['Loads'][0]['Phase1Customers']);
        $this->assertNotEmpty($payload['engineering_omissions']);
        $snapshot['configuration']['settings']['simple_mdb_generation'] = false;
        $revision->snapshot = $snapshot;
        $this->expectException(ValidationException::class);
        $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
    }

    public function test_complete_configuration_maps_identical_tables_in_both_modes(): void
    {
        [$revision, $template] = $this->fixture();
        $full = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $revision->snapshot = $snapshot;
        $simple = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $this->assertSame($full['tables'], $simple['tables']);
    }

    public function test_simple_mode_still_rejects_invalid_identifiers_and_connectivity(): void
    {
        foreach (['missing_code', 'duplicate_id', 'self', 'duplicate_pair', 'cycle', 'disconnected', 'empty_network', 'missing_root', 'missing_substation'] as $problem) {
            [$revision, $template] = $this->fixture();
            $snapshot = $revision->snapshot;
            $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
            $transformer = &$snapshot['transformers'][0];
            match ($problem) {
                'missing_code' => $transformer['code'] = '',
                'duplicate_id' => $transformer['sections'][1]['id'] = 1,
                'self' => $transformer['sections'][0]['end_waypoint_id'] = 1,
                'duplicate_pair' => $transformer['sections'][1]['end_waypoint_id'] = 2,
                'cycle' => $transformer['sections'][] = array_replace($transformer['sections'][0], ['id' => 3, 'start_waypoint_id' => 2, 'end_waypoint_id' => 3]),
                'disconnected' => $transformer['source_waypoint_id'] = 4,
                'empty_network' => $transformer['sections'] = [],
                'missing_root' => $transformer['source_waypoint_id'] = null,
                'missing_substation' => $snapshot['transformers'][0]['header']['substation_name'] = null,
            };
            if ($problem === 'disconnected') {
                $snapshot['waypoints'][] = ['id' => 4, 'name' => '004', 'latitude' => 30, 'longitude' => 71];
                $snapshot['configuration']['settings']['mapping']['networks']['T-fixture']['source_sections'][1]['to'] = 'wp:4';
            }
            unset($transformer);
            $revision->snapshot = $snapshot;
            try {
                $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
                $this->fail('Simple mode accepted '.$problem);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors(), $problem);
            }
        }
    }

    public function test_simple_v2_devices_allow_blank_settings_but_retain_section_integrity(): void
    {
        [$revision, $template] = $this->fixture();
        $snapshot = $revision->snapshot;
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $mapping = &$snapshot['configuration']['settings']['mapping'];
        $mapping['version'] = ExportPayloadBuilder::EXTENDED_VERSION;
        $mapping['networks']['T-fixture']['generators'] = [['pv_record_id' => 8]];
        $snapshot['transformers'][0]['pv_records'] = [['id' => 8, 'section_id' => 1, 'installed_capacity_kw' => null, 'meter_number' => '001-AB/09']];
        $revision->snapshot = $snapshot;
        $payload = $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
        $this->assertSame([['SectionId' => $payload['tables']['InstSection'][2]['SectionId']]], $payload['tables']['InstDGens']);
        $this->assertSame([], $payload['tables']['InstMymDGens']);
        $this->assertSame('001-AB/09', $payload['pv_records'][0]['meter_number']);
        $snapshot['transformers'][0]['pv_records'][0]['section_id'] = 999;
        $revision->snapshot = $snapshot;
        $this->expectException(ValidationException::class);
        $this->builder()->build($revision, $template, 1, str_repeat('c', 64));
    }
}
