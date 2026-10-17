<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\SurveyBatch;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\EntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimpleMdbEntryValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_mode_allows_missing_engineering_observations_without_changing_recorded_data(): void
    {
        $data = $this->fixture();
        $original = $data;
        $this->assertSame([], app(EntryService::class)->issues($data));
        $this->assertSame($original, $data);
    }

    public function test_full_mode_keeps_existing_header_row_and_pv_engineering_requirements(): void
    {
        $data = $this->fixture();
        unset($data['configuration']);
        $codes = $this->codes($data);
        foreach (['incomplete_operator_header', 'missing_operator_capacity', 'incomplete_entry_conductors',
            'incomplete_entry_equipment', 'missing_entry_height_unit', 'incomplete_entry_pv_details'] as $code) {
            $this->assertContains($code, $codes);
        }
        $data['configuration'] = ['settings' => ['simple_mdb_generation' => false]];
        $this->assertSame($codes, $this->codes($data));
    }

    public function test_simple_mode_tolerates_unrecorded_conductors_but_preserves_conflicting_observations(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['entry_rows'][0]['conductors']['R'] = 'A';
        $this->assertSame([], $this->codes($data));
        $data['transformers'][0]['entry_rows'][1]['conductors']['R'] = 'W';
        $this->assertContains('pair_conductors_differ', $this->codes($data));
        $data['transformers'][0]['entry_rows'][1]['conductors']['R'] = '';
        $this->assertContains('pair_conductors_differ', $this->codes($data));
    }

    public function test_simple_mode_retains_survey_identity_source_matching_pdf_and_pair_requirements(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['entry_rows'][1]['gpx_source_id'] = 99;
        $this->assertContains('entry_waypoint_source_mismatch', $this->codes($data));
        $data = $this->fixture();
        $data['transformers'][0]['entry_rows'][1]['composite_identifier'] = 'wrong';
        $this->assertContains('invalid_survey_identifier', $this->codes($data));
        $data = $this->fixture();
        $data['transformers'][0]['entry_rows'][1]['source_page'] = 2;
        $this->assertContains('invalid_entry_pdf_source', $this->codes($data));
        $data = $this->fixture();
        array_pop($data['transformers'][0]['entry_rows']);
        $this->assertContains('incomplete_entry_pair', $this->codes($data));
        $data = $this->fixture();
        $data['transformers'][0]['header']['feeder_identifier'] = '';
        $this->assertContains('incomplete_operator_header', $this->codes($data));
    }

    public function test_simple_mode_still_requires_valid_transformer_coordinates_and_unambiguous_gpx(): void
    {
        $data = $this->fixture();
        $data['waypoints'][1]['latitude'] = 91;
        $this->assertContains('missing_operator_root', $this->codes($data));
        $data = $this->fixture();
        $duplicate = $data['waypoints'][1];
        $duplicate['id'] = 3;
        $data['waypoints'][] = $duplicate;
        $codes = $this->codes($data);
        $this->assertContains('entry_ambiguous_waypoint', $codes);
        $this->assertContains('missing_operator_root', $codes);
    }

    public function test_simple_mode_still_requires_pv_identifiers_and_details_for_recorded_pv_consumers(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['entry_rows'][1]['pv_details'][0]['reference'] = '';
        $this->assertContains('incomplete_entry_pv_details', $this->codes($data));
        $data['transformers'][0]['entry_rows'][1]['pv_details'][0]['meter_number'] = '001-AB/09';
        $this->assertNotContains('incomplete_entry_pv_details', $this->codes($data));
        $data['transformers'][0]['entry_rows'][1]['pv_details'] = [];
        $this->assertContains('missing_entry_pv_details', $this->codes($data));
    }

    public function test_simple_mode_allows_blank_capacity_and_height_but_rejects_supplied_invalid_values(): void
    {
        $data = $this->fixture();
        $data['transformers'][0]['capacity_kva'] = 0;
        $data['transformers'][0]['entry_rows'][0]['pole_height'] = -1;
        $codes = $this->codes($data);
        $this->assertContains('missing_operator_capacity', $codes);
        $this->assertContains('incomplete_entry_equipment', $codes);
    }

    public function test_live_batch_validation_reads_the_server_project_setting(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $project = Project::create(['code' => 'SIMPLE', 'name' => 'Simple entry fixture', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $sub = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $sub->id, 'code' => 'GS', 'name' => 'Substation']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $sub->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F', 'feeder_name' => 'Feeder', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => '01', 'name' => 'Team', 'status' => 'active']);
        $batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id,
            'survey_date' => '2026-10-17', 'created_by' => $admin->id]);
        $data = $this->fixture();
        $sources = [];
        foreach ($data['sources'] as $source) {
            $sources[$source['id']] = $batch->sources()->create(['kind' => $source['kind'], 'path' => 'entry.'.$source['kind'],
                'original_name' => 'entry.'.$source['kind'], 'sha256' => str_repeat('a', 64), 'bytes' => 10,
                'mime' => $source['kind'] === 'pdf' ? 'application/pdf' : 'application/gpx+xml', 'uploaded_by' => $admin->id,
                'status' => 'ready', 'metadata' => $source['metadata'] ?? []]);
        }
        foreach ($data['waypoints'] as $point) {
            $sources[1]->waypoints()->create(['batch_id' => $batch->id, 'name' => $point['name'], 'latitude' => $point['latitude'], 'longitude' => $point['longitude']]);
        }
        $transformer = $batch->transformers()->create(['code' => 'T-1', 'capacity_kva' => null, 'header' => $data['transformers'][0]['header']]);
        foreach ($data['transformers'][0]['entry_rows'] as $row) {
            unset($row['id'], $row['transformer_id'], $row['section_id']);
            $row['client_uuid'] = (string) Str::uuid();
            $row['save_uuid'] = (string) Str::uuid();
            $row['save_hash'] = hash('sha256', json_encode($row));
            $row['gpx_source_id'] = $sources[1]->id;
            $row['source_pdf_id'] = $sources[2]->id;
            $transformer->entryRows()->create($row);
        }
        $this->assertContains('missing_operator_capacity', array_column(app(EntryService::class)->issues($batch->fresh()), 'code'));
        Configuration::create(['project_id' => $project->id, 'settings' => ['simple_mdb_generation' => true]]);
        $this->assertSame([], app(EntryService::class)->issues($batch->fresh()));
    }

    private function codes(array $data): array
    {
        return array_column(app(EntryService::class)->issues($data), 'code');
    }

    private function fixture(): array
    {
        $row = ['transformer_id' => 1, 'section_id' => 1, 'pair_number' => 1, 'group_number' => '01', 'row_date' => '2026-10-17',
            'identity_version' => 2, 'gpx_source_id' => 1, 'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => '1',
            'conductors' => array_fill_keys(['R', 'Y', 'B', 'N'], null), 'equipment_type' => null, 'pole_class' => null,
            'pole_height' => null, 'pole_height_unit' => null, 'consumers' => ['pv' => 0], 'pv_details' => [], 'manually_verified' => true];

        return ['configuration' => ['settings' => ['simple_mdb_generation' => true]],
            'sources' => [['id' => 1, 'kind' => 'gpx'], ['id' => 2, 'kind' => 'pdf', 'metadata' => ['page_count' => 1]]],
            'waypoints' => [['id' => 1, 'source_file_id' => 1, 'name' => '001', 'latitude' => 30.1, 'longitude' => 71.1],
                ['id' => 2, 'source_file_id' => 1, 'name' => '002', 'latitude' => 30.101, 'longitude' => 71.101]],
            'transformers' => [['id' => 1, 'code' => 'T-1', 'capacity_kva' => null, 'header' => ['operator_entry' => true,
                'substation_name' => 'Substation', 'feeder_name' => 'Feeder', 'feeder_identifier' => 'F', 'division' => 'Division',
                'subdivision' => 'Subdivision', 'subdivision_code' => 'SD', 'inspector' => 'Inspector', 'location' => 'Location', 'survey_date' => '2026-10-17'],
                'entry_rows' => [array_replace($row, ['id' => 1, 'designation' => 'S', 'waypoint_reference' => '001', 'composite_identifier' => '01171026001']),
                    array_replace($row, ['id' => 2, 'designation' => 'E', 'waypoint_reference' => '002', 'composite_identifier' => '01171026002',
                        'consumers' => ['pv' => 1], 'pv_details' => [['reference' => 'PV-01', 'service_load_kw' => null, 'installed_capacity_kw' => null]]])]]]];
    }
}
