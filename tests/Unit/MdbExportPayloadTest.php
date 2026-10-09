<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use App\Services\Mdb\ExportPayloadBuilder;
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
        $snapshot = ['configuration' => ['epsg' => 32643, 'approved_by' => 2, 'approved_at' => '2026-10-09', 'settings' => $settings], 'template' => ['sha256' => $template->sha256, 'version' => $template->version], 'waypoints' => $waypoints, 'transformers' => [['id' => 1, 'code' => 'T-fixture', 'source_waypoint_id' => 1, 'sections' => $sections, 'pv_records' => []]]];
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
        $this->assertCount(1, $payload['tables']['Loads']);
        $this->assertSame(1.0, $payload['tables']['Loads'][0]['Phase1Kva']);
        $this->assertSame('endpoint_estimate', $payload['provenance'][0]['length_source']);
        $this->assertSame($payload['tables'], $this->builder()->build($revision, $template, 1, str_repeat('d', 64))['tables']);
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
}
