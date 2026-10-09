<?php

namespace Tests\Unit;

use App\Models\Mdb\Waypoint;
use App\Services\Mdb\NetworkValidator;
use App\Services\Mdb\ProjectionService;
use App\Services\Mdb\SnapshotService;
use PHPUnit\Framework\TestCase;

class MdbNetworkValidatorTest extends TestCase
{
    private function validator(): NetworkValidator
    {
        $projection = new class extends ProjectionService
        {
            public function projectMany(array $points, int $epsg): array
            {
                return array_map(fn ($point) => ['x' => $point['longitude'] * 100000, 'y' => $point['latitude'] * 100000], $points);
            }
        };

        return new NetworkValidator($projection, new SnapshotService);
    }

    private function fixture(): array
    {
        $row = fn ($id, $from, $to) => [
            'id' => $id, 'start_reference' => $from, 'end_reference' => $to, 'start_source_file_id' => 1, 'end_source_file_id' => 1,
            'phases' => ['R', 'N'], 'conductors' => ['R' => 'DOG', 'N' => 'DOG'], 'equipment_type' => 'pole', 'equipment_ref' => 'POLE',
            'pole_class' => 'concrete', 'pole_height' => 11, 'pole_height_unit' => 'm', 'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => (string) $id,
            'original_entry' => ['manually_verified' => true],
            'consumers' => array_map(fn ($category) => ['category' => $category, 'count' => 0, 'original_value' => '0', 'demand' => null], ['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st']),
        ];

        return [
            'batch' => ['id' => 1, 'project_id' => 1],
            'configuration' => ['epsg' => 32643, 'approved_by' => 1, 'approved_at' => '2026-10-09T00:00:00Z', 'load_assumptions' => ['method' => 'measured'], 'settings' => [
                'frequency_hz' => 50, 'nominal_voltage_kv' => 0.415, 'conductor_library_revision' => 'v1', 'transformer_library_revision' => 'v1',
                'mapping' => ['version' => 'v1', 'reviewed_by' => 1, 'reviewed_at' => '2026-10-09T00:00:00Z', 'networks' => ['T1' => ['source_nodes' => [['key' => 'source']], 'source_sections' => [['key' => 'supply']], 'transformer_fields' => ['TransformerType' => '100KVA']]]],
            ]],
            'template' => ['approved_by' => 1, 'approved_at' => '2026-10-09T00:00:00Z', 'metadata' => ['equipment_references' => ['conductors' => ['DOG'], 'configurations' => ['POLE'], 'transformers' => ['100KVA']]]],
            'sources' => [
                ['id' => 1, 'kind' => 'gpx', 'status' => 'ready', 'original_name' => 'points.gpx', 'sha256' => str_repeat('a', 64)],
                ['id' => 2, 'kind' => 'pdf', 'status' => 'ready', 'original_name' => 'survey.pdf', 'sha256' => str_repeat('b', 64), 'metadata' => ['page_count' => 27]],
            ],
            'waypoints' => [
                ['id' => 1, 'batch_id' => 1, 'source_file_id' => 1, 'name' => '001', 'latitude' => 31.7, 'longitude' => 73.5],
                ['id' => 2, 'batch_id' => 1, 'source_file_id' => 1, 'name' => '10', 'latitude' => 31.7, 'longitude' => 73.501],
                ['id' => 3, 'batch_id' => 1, 'source_file_id' => 1, 'name' => 'SSS', 'latitude' => 31.701, 'longitude' => 73.5],
            ],
            'transformers' => [['id' => 1, 'code' => 'T1', 'capacity_kva' => 100, 'source_waypoint_id' => 1, 'header' => ['manually_verified' => true,
                'feeder_identifier' => 'F1', 'feeder_name' => 'Survey feeder', 'substation_identifier' => 'G1', 'substation_name' => 'Survey substation',
                'make' => 'Surveyed make', 'location' => 'Verified location', 'mounting' => 'Double pole', 'survey_date' => '2026-10-09', 'team_group' => 'S1', 'inspector' => 'Survey inspector',
                'pages' => [['source_pdf_id' => 2, 'page' => 1, 'role' => 'header', 'confirmed' => true]],
            ], 'sections' => [$row(1, '001', '10'), $row(2, '001', 'SSS')]]],
        ];
    }

    public function test_branches_follow_exact_pairs_preserving_text_names(): void
    {
        $result = $this->validator()->validate($this->fixture());
        self::assertTrue($result['valid'], json_encode($result['errors']));
        self::assertCount(3, $result['topology'][0]['nodes']);
        self::assertSame([1, 1], array_column($result['topology'][0]['sections'], 'start_waypoint_id'));
        self::assertSame([2, 3], array_column($result['topology'][0]['sections'], 'end_waypoint_id'));
        self::assertEqualsWithDelta(100.0, $result['topology'][0]['sections'][0]['estimated_length_m'], 0.001);
        self::assertSame('endpoint_estimate', $result['topology'][0]['sections'][0]['length_kind']);
        self::assertSame($result['topology'], $this->validator()->validate($this->fixture())['topology']);
    }

    public function test_missing_sample_waypoints_878_879_link_to_pdf_row(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['sections'][0]['start_reference'] = '878';
        $data['transformers'][0]['sections'][0]['end_reference'] = '879';
        $errors = array_values(array_filter($this->validator()->validate($data)['errors'], fn ($error) => $error['code'] === 'missing_waypoint'));
        self::assertCount(2, $errors);
        self::assertStringContainsString('878', $errors[0]['message']);
        self::assertStringContainsString('879', $errors[1]['message']);
        self::assertSame(1, $errors[0]['source_page']);
        self::assertSame('1', $errors[0]['source_row']);
    }

    public function test_duplicate_names_conflicting_coordinates_and_ambiguity_block(): void
    {
        $data = $this->fixture();
        $duplicate = $data['waypoints'][0];
        $duplicate['id'] = 4;
        $duplicate['latitude'] = 31.705;
        $data['waypoints'][] = $duplicate;
        $codes = array_column($this->validator()->validate($data)['errors'], 'code');
        self::assertContains('conflicting_coordinates', $codes);
        self::assertContains('ambiguous_waypoint', $codes);
    }

    public function test_scoping_same_name_to_different_sources_resolves_exact_point(): void
    {
        $data = $this->fixture();
        $source = $data['sources'][0];
        $source['id'] = 3;
        $data['sources'][] = $source;
        $point = $data['waypoints'][0];
        $point['id'] = 4;
        $point['source_file_id'] = 3;
        $data['waypoints'][] = $point;
        self::assertTrue($this->validator()->validate($data)['valid']);
        $data['transformers'][0]['sections'][0]['start_source_file_id'] = null;
        self::assertContains('ambiguous_waypoint', array_column($this->validator()->validate($data)['errors'], 'code'));
    }

    public function test_cycle_disconnected_and_duplicate_sections_are_reported(): void
    {
        $data = $this->fixture();
        $extra = $data['transformers'][0]['sections'][0];
        $extra['id'] = 3;
        $extra['start_reference'] = '10';
        $extra['end_reference'] = 'SSS';
        $data['transformers'][0]['sections'][] = $extra;
        self::assertContains('cycle_detected', array_column($this->validator()->validate($data)['errors'], 'code'));
        $data = $this->fixture();
        unset($data['transformers'][0]['sections'][0]);
        $data['transformers'][0]['source_waypoint_id'] = 2;
        self::assertContains('disconnected_network', array_column($this->validator()->validate($data)['errors'], 'code'));
        $data = $this->fixture();
        $duplicate = $data['transformers'][0]['sections'][0];
        $duplicate['id'] = 3;
        $data['transformers'][0]['sections'][] = $duplicate;
        self::assertContains('duplicate_section', array_column($this->validator()->validate($data)['errors'], 'code'));
    }

    public function test_count_is_separate_from_load_and_null_is_not_zero(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['sections'][0]['consumers'][0]['count'] = 5;
        self::assertContains('unapproved_demand', array_column($this->validator()->validate($data)['errors'], 'code'));
        $data['transformers'][0]['sections'][0]['consumers'][0]['count'] = null;
        self::assertContains('unverified_consumer_count', array_column($this->validator()->validate($data)['errors'], 'code'));
        $data['transformers'][0]['sections'][0]['consumers'] = [];
        self::assertContains('missing_consumer_category', array_column($this->validator()->validate($data)['errors'], 'code'));
    }

    public function test_unverified_rows_missing_units_and_length_override_block(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['sections'][0]['original_entry'] = [];
        $data['transformers'][0]['sections'][0]['pole_height_unit'] = null;
        $data['transformers'][0]['sections'][0]['measured_length_m'] = 123;
        $codes = array_column($this->validator()->validate($data)['errors'], 'code');
        self::assertContains('unverified_survey_row', $codes);
        self::assertContains('missing_pole_units', $codes);
        self::assertContains('unapproved_measured_length', $codes);
    }

    public function test_correction_preserves_original_coordinates_and_needs_approval(): void
    {
        $data = $this->fixture();
        $data['waypoints'][1]['correction'] = ['latitude' => 31.7, 'longitude' => 73.502, 'reason' => 'Verified return survey'];
        self::assertContains('unapproved_coordinate_correction', array_column($this->validator()->validate($data)['errors'], 'code'));
        $data['waypoints'][1]['correction_approved_by'] = 1;
        $data['waypoints'][1]['correction_approved_at'] = '2026-10-09T00:00:00Z';
        $result = $this->validator()->validate($data);
        self::assertTrue($result['valid'], json_encode($result['errors']));
        self::assertEqualsWithDelta(200, $result['topology'][0]['sections'][0]['length_m'], 0.001);
        self::assertSame(73.501, $data['waypoints'][1]['longitude']);
    }

    public function test_snapshot_hash_is_independent_of_associative_key_order(): void
    {
        $snapshots = new SnapshotService;
        self::assertSame($snapshots->hash(['z' => 2, 'a' => ['y' => 3, 'x' => 4]]), $snapshots->hash(['a' => ['x' => 4, 'y' => 3], 'z' => 2]));
        self::assertNotSame($snapshots->hash(['sources' => [['sha256' => str_repeat('a', 64)]]]), $snapshots->hash(['sources' => [['sha256' => str_repeat('b', 64)]]]));
    }

    public function test_approved_phase_demand_allocation_is_checked_separately_from_counts(): void
    {
        $data = $this->fixture();
        $consumer = &$data['transformers'][0]['sections'][0]['consumers'][0];
        $consumer['count'] = 5;
        $consumer['demand'] = ['method' => 'measured', 'evidence' => 'Meter report', 'approved_by' => 1, 'phase_values' => ['R' => ['customers' => 5, 'kw' => 3, 'kvar' => 4, 'kva' => 5]]];
        self::assertTrue($this->validator()->validate($data)['valid']);
        $consumer['demand']['phase_values']['R']['customers'] = 4;
        self::assertContains('customer_allocation_mismatch', array_column($this->validator()->validate($data)['errors'], 'code'));
        $consumer['demand']['phase_values']['R']['customers'] = 5;
        $consumer['demand']['phase_values']['R']['kva'] = 10;
        self::assertContains('inconsistent_demand', array_column($this->validator()->validate($data)['errors'], 'code'));
    }

    public function test_headers_and_continuation_pages_require_verified_source_associations(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['header']['mounting'] = null;
        $data['transformers'][0]['header']['survey_date'] = '2026-02-31';
        $data['transformers'][0]['header']['pages'] = [];
        $codes = array_column($this->validator()->validate($data)['errors'], 'code');
        self::assertContains('incomplete_transformer_header', $codes);
        self::assertContains('invalid_header_survey_date', $codes);
        self::assertContains('missing_header_provenance', $codes);
        self::assertContains('unassociated_section_page', $codes);
        $data = $this->fixture();
        $data['transformers'][0]['sections'][0]['source_page'] = 2;
        self::assertContains('unassociated_section_page', array_column($this->validator()->validate($data)['errors'], 'code'));
        $data['transformers'][0]['header']['pages'][] = ['source_pdf_id' => 2, 'page' => 2, 'role' => 'continuation', 'confirmed' => true];
        self::assertTrue($this->validator()->validate($data)['valid']);
        $data['transformers'][0]['header']['pages'][] = ['source_pdf_id' => 2, 'page' => 1, 'role' => 'pv', 'confirmed' => true];
        self::assertTrue($this->validator()->validate($data)['valid']);
    }

    public function test_malformed_engineering_library_metadata_has_readable_errors(): void
    {
        $data = $this->fixture();
        $data['template']['metadata']['equipment_references']['conductors'] = 'DOG';
        $data['template']['metadata']['equipment_references']['configurations'] = true;
        $data['configuration']['settings']['mapping']['networks']['T1'] = 'unreviewed';
        $codes = array_column($this->validator()->validate($data)['errors'], 'code');
        self::assertContains('missing_equipment_library', $codes);
        self::assertContains('unknown_conductor', $codes);
        self::assertContains('missing_source_mapping', $codes);
    }

    public function test_missing_template_library_and_unverified_pv_provenance_block(): void
    {
        $data = $this->fixture();
        $data['template']['metadata']['equipment_references']['transformers'] = [];
        $data['transformers'][0]['pv_records'] = [['reference' => '000123', 'installed_capacity_kw' => 5, 'original_entry' => []]];
        $codes = array_column($this->validator()->validate($data)['errors'], 'code');
        self::assertContains('missing_equipment_library', $codes);
        self::assertContains('unverified_pv_record', $codes);
        self::assertContains('missing_pv_provenance', $codes);
        self::assertContains('pv_model_unmapped', $codes);
        $data = $this->fixture();
        $data['transformers'][0]['pv_records'] = [['reference' => '000123', 'installed_capacity_kw' => 5, 'original_entry' => ['manually_verified' => true, 'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => 'PV1']]];
        $data['configuration']['settings']['mapping']['pv_exclusion'] = ['reason' => 'Engineer-authorized base case without PV', 'reviewed_by' => 1, 'reviewed_at' => '2026-10-09T00:00:00Z'];
        self::assertTrue($this->validator()->validate($data)['valid']);
        $data['configuration']['settings']['mapping']['pv_exclusion']['reviewed_by'] = 2;
        self::assertContains('pv_model_unmapped', array_column($this->validator()->validate($data)['errors'], 'code'));
    }

    public function test_gpx_timestamp_sql_values_remain_utc_with_karachi_default(): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Karachi');
            $point = new Waypoint;
            $point->setRawAttributes(['recorded_at' => '2026-10-09 06:30:00']);
            self::assertSame('2026-10-09T06:30:00+00:00', $point->recorded_at->toIso8601String());
            self::assertSame('2026-10-09T06:30:00+00:00', $point->toArray()['recorded_at']);
            self::assertSame('11:30', $point->recorded_at->setTimezone('Asia/Karachi')->format('H:i'));
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
