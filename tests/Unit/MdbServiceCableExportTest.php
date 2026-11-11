<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\ProjectionService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MdbServiceCableExportTest extends TestCase
{
    private function snapshot(): array
    {
        $s = MdbInstSectionGenerationTest::snapshot();
        $s['waypoints'][] = ['id' => 3, 'batch_id' => 1, 'source_file_id' => 1, 'name' => '026', 'latitude' => 34.164, 'longitude' => 73.258, 'elevation' => 10];
        $s['transformers'][0]['entry_rows'][1]['consumers'] = ['rs' => 3];

        return $s;
    }

    private function cable(array $changes = []): array
    {
        return array_replace(['id' => 1, 'entry_row_id' => 2, 'category' => 'rs', 'consumer_number' => 1, 'group_number' => '02',
            'row_date' => '2026-10-08', 'waypoint_reference' => '026', 'composite_identifier' => '02081026026',
            'gpx_source_id' => 1, 'meter_waypoint_id' => 3, 'source_pdf_id' => 2, 'source_page' => 1,
            'phase' => null, 'measured_length_m' => null, 'length_evidence' => null], $changes);
    }

    private function build(array $snapshot): array
    {
        return app(ExportPayloadBuilder::class)->build(new Revision(['batch_id' => 1, 'number' => 1, 'sha256' => str_repeat('a', 64), 'snapshot' => $snapshot]),
            new Template($snapshot['template'] + ['active' => true]), 1, str_repeat('b', 64));
    }

    public function test_disabled_and_empty_feature_keeps_the_entire_existing_export_identical(): void
    {
        $s = $this->snapshot();
        $before = $this->build($s);
        $s['transformers'][0]['service_cables_enabled'] = false;
        $s['transformers'][0]['service_cables'] = [];
        $this->assertSame($before, $this->build($s));
        $s['transformers'][0]['service_cables_enabled'] = true;
        $this->assertSame($before, $this->build($s));
    }

    public function test_partial_meter_coverage_changes_only_the_linked_endpoint_and_length_without_adding_load(): void
    {
        $s = $this->snapshot();
        $baseline = $this->build($s);
        $s['transformers'][0]['service_cables_enabled'] = true;
        $s['transformers'][0]['service_cables'] = [$this->cable(['measured_length_m' => 24.5, 'length_evidence' => 'Measured on PDF page 1'])];
        $before = $s;
        $result = $this->build($s);
        $this->assertSame($before, $s);
        $this->assertSame($baseline['tables']['Loads'], $result['tables']['Loads']);
        $this->assertSame($baseline['tables']['InstMymLoads'], $result['tables']['InstMymLoads']);
        $this->assertCount(count($baseline['tables']['Node']), $result['tables']['Node']);
        $nodes = array_column($result['tables']['Node'], null, 'NodeId');
        $oldNodes = array_column($baseline['tables']['Node'], null, 'NodeId');
        $projected = app(ProjectionService::class)->project(34.164, 73.258, 32643);
        $this->assertSame($projected['x'], $nodes['81026001.1']['X']);
        $this->assertSame($projected['y'], $nodes['81026001.1']['Y']);
        $this->assertSame($oldNodes['81026001.2'], $nodes['81026001.2']);
        $sections = array_column($result['tables']['InstSection'], null, 'SectionId');
        $this->assertSame(24.5, $sections['00040701081026001.1']['SectionLength_MUL']);
        $this->assertSame('81026001', $sections['00040701081026001.1']['FromNodeId']);
        $this->assertSame('recorded_measured', $result['service_cable_provenance'][0]['length_source']);
    }

    public function test_waypoint_distance_is_labelled_as_estimated_and_does_not_use_the_pole_height_formula(): void
    {
        $s = $this->snapshot();
        $s['transformers'][0]['service_cables_enabled'] = true;
        $s['transformers'][0]['service_cables'] = [$this->cable()];
        $result = $this->build($s);
        $this->assertSame('coordinate_distance_estimate', $result['service_cable_provenance'][0]['length_source']);
        $this->assertGreaterThan(20, $result['service_cable_provenance'][0]['length_m']);
    }

    public function test_one_three_phase_meter_locates_all_three_model_legs_without_adding_consumers(): void
    {
        $s = MdbInstSectionGenerationTest::mixedSnapshot();
        $s['waypoints'][] = ['id' => 3, 'source_file_id' => 1, 'name' => '026', 'latitude' => 34.164, 'longitude' => 73.258];
        $baseline = $this->build($s);
        $s['transformers'][0]['service_cables_enabled'] = true;
        $s['transformers'][0]['service_cables'] = [$this->cable(['category' => 'rl', 'phase' => 'RYB'])];
        $result = $this->build($s);
        $this->assertSame($baseline['tables']['Loads'], $result['tables']['Loads']);
        $this->assertCount(3, $result['service_cable_provenance']);
        $this->assertCount(1, $result['service_cables']);
        foreach ($result['service_cable_provenance'] as $record) $this->assertSame('02081026026', $record['meter_identity']);
    }

    public function test_separate_pole_meters_can_share_e_coordinates_without_a_measured_length(): void
    {
        $s = $this->snapshot();
        $pole = collect($s['waypoints'])->firstWhere('name', $s['transformers'][0]['entry_rows'][1]['waypoint_reference']);
        $s['waypoints'][2]['latitude'] = $pole['latitude'];
        $s['waypoints'][2]['longitude'] = $pole['longitude'];
        $s['waypoints'][] = array_replace($s['waypoints'][2], ['id' => 4, 'name' => '027']);
        $s['transformers'][0]['service_cables_enabled'] = true;
        $s['transformers'][0]['service_cables'] = [$this->cable(), $this->cable([
            'id' => 2, 'consumer_number' => 2, 'waypoint_reference' => '027',
            'composite_identifier' => '02081026027', 'meter_waypoint_id' => 4,
        ])];
        $result = $this->build($s);
        $this->assertCount(2, $result['service_cable_provenance']);
        foreach ($result['service_cable_provenance'] as $record) {
            $this->assertEquals(0, $record['length_m']);
            $this->assertSame('coordinate_distance_estimate', $record['length_source']);
        }
    }

    public function test_frozen_export_rejects_meter_records_exceeding_the_e_pole_count(): void
    {
        $s = $this->snapshot();
        $s['transformers'][0]['service_cables_enabled'] = true;
        $s['transformers'][0]['service_cables'] = [$this->cable(['consumer_number' => 4])];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds the recorded RS count');
        $this->build($s);
    }

    public function test_recorded_meter_phase_uses_its_existing_consumer_slot_and_reconciles_the_other_phase_counts(): void
    {
        $s = $this->snapshot();
        $s['transformers'][0]['service_cables_enabled'] = true;
        $s['transformers'][0]['service_cables'] = [$this->cable(['consumer_number' => 3, 'phase' => 'B'])];
        $s['transformers'][0]['entry_rows'][1]['consumer_phases'] = ['rs' => ['R' => 2, 'Y' => 0, 'B' => 1]];
        $result = $this->build($s);
        $sections = array_column($result['tables']['InstSection'], null, 'SectionId');
        $this->assertSame('RN', $sections['00040701081026001.1']['SectionPhases']);
        $this->assertSame('RN', $sections['00040701081026001.2']['SectionPhases']);
        $this->assertSame('BN', $sections['00040701081026001.3']['SectionPhases']);
    }
}
