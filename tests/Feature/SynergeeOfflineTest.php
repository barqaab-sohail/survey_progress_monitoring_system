<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Mdb\Revision;
use App\Models\Mdb\SourceFile;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\Mdb\VerificationDecision;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\AccessPowerShell;
use App\Services\Mdb\EngineeringConfiguration;
use App\Services\Mdb\ExportService;
use App\Services\Mdb\ProjectionService;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SynergeeTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\DataEntrySnapshot;
use Tests\Fixtures\SynergeeSnapshot;
use Tests\TestCase;

class SynergeeOfflineTest extends TestCase
{
    use RefreshDatabase;

    private function productionBatch(bool $simple = false): SurveyBatch
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $verifier = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin);
        $project = Project::create(['code' => 'OFF', 'name' => 'Offline fixture', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C', 'name' => 'C']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'D']);
        $sub = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'S']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $sub->id, 'code' => 'G', 'name' => 'G']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $sub->id,
            'grid_station_id' => $grid->id, 'feeder_code' => 'F1', 'feeder_name' => 'Synthetic feeder', 'total_transformers' => 0, 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => '01', 'name' => 'Team', 'status' => 'active']);
        $snapshot = SynergeeSnapshot::make();
        if ($simple) {
            $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
            $snapshot['template']['approved_by'] = null;
            $snapshot['template']['approved_at'] = null;
            $snapshot['template']['metadata']['system_reference_schema'] = true;
        }
        Storage::disk('local')->put('fixture/template.mdb', file_get_contents(resource_path('mdb/synergee-empty.mdb')));
        $template = Template::create($snapshot['template'] + ['disk' => 'local', 'path' => 'fixture/template.mdb', 'active' => true]);
        $batch = SurveyBatch::create(['project_id' => $project->id, 'feeder_id' => $feeder->id, 'survey_team_id' => $team->id,
            'survey_date' => '2026-10-09', 'created_by' => $admin->id, 'revision' => 1, 'staged_workflow' => true, 'survey_status' => 'approved', 'status' => 'approved', 'entry_actor_ids' => [$admin->id]]);
        $snapshot['batch']['id'] = $batch->id;
        $snapshot['template']['id'] = $template->id;
        foreach ($snapshot['sources'] as &$source) {
            $source['disk'] = 'local';
            $source['path'] = 'fixture/'.$source['original_name'];
            $bytes = $source['kind'] === 'gpx' ? SynergeeSnapshot::gpx() : '%PDF fixture';
            Storage::disk('local')->put($source['path'], $bytes);
            $source += ['bytes' => strlen($bytes), 'mime' => $source['kind'] === 'gpx' ? 'application/gpx+xml' : 'application/pdf',
                'version' => 1, 'parent_source_id' => null, 'uploaded_by' => $admin->id, 'metadata' => $source['metadata'] ?? []];
            SourceFile::create($source + ['batch_id' => $batch->id]);
        }
        unset($source);
        $revision = Revision::create(['batch_id' => $batch->id, 'number' => 1, 'snapshot' => $snapshot,
            'sha256' => app(SnapshotService::class)->hash($snapshot), 'created_by' => $admin->id, 'frozen_at' => now()]);
        VerificationDecision::create(['batch_id' => $batch->id, 'revision_id' => $revision->id, 'decided_by' => $verifier->id, 'decision' => 'approve']);
        $batch->update(['approved_revision_id' => $revision->id]);
        $this->partialMock(SnapshotService::class)->shouldReceive('snapshot')->andReturn($snapshot);

        return $batch->fresh();
    }

    public function test_production_export_is_frozen_portable_and_does_not_dispatch_a_windows_job(): void
    {
        $batch = $this->productionBatch();
        config(['mdb_workflow.export.driver' => 'disabled']);
        $url = route('mdb-workflow.synergee-json.download', [$batch, 'transformer_id' => 1, 'revision_sha256' => $batch->approvedRevision->sha256]);
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/json');
        $package = app(SynergeeTransfer::class)->read($response->streamedContent());
        $this->assertSame(['T-SYNTHETIC'], $package['scope']['transformer_codes']);
        $this->assertSame('F1', $package['source']['feeder_code']);
        $this->assertDatabaseCount('mdb_workflow_exports', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.synergee_json_exported']);
        $this->get(route('mdb-workflow.synergee-json.download', [$batch, 'revision_sha256' => str_repeat('0', 64)]))->assertConflict();
        $batch->update(['survey_status' => 'awaiting_verification']);
        $this->get($url)->assertForbidden();
        $batch->update(['survey_status' => 'approved']);
        $this->actingAs(User::factory()->create(['role' => UserRole::Surveyor]))->get($url)->assertForbidden();
    }

    public function test_simple_production_export_accepts_the_active_system_schema_template(): void
    {
        $batch = $this->productionBatch(true);
        $transfer = app(SynergeeTransfer::class);
        $package = $transfer->export($batch, 1);
        $read = $transfer->read(json_encode($package, JSON_THROW_ON_ERROR));
        $this->assertTrue($read['snapshot']['configuration']['settings']['simple_mdb_generation']);
        $this->assertNull($read['snapshot']['template']['approved_by']);
        $this->assertTrue($read['snapshot']['template']['metadata']['system_reference_schema']);
    }

    public function test_production_enforces_its_own_frozen_integrity_check(): void
    {
        $batch = $this->productionBatch();
        $this->mock(ExportService::class)->shouldReceive('assertApproved')->andReturnUsing(fn ($locked) => $locked->approvedRevision);
        $snapshot = $batch->approvedRevision->snapshot;
        $snapshot['transformers'][0]['code'] = 'CORRUPTED';
        DB::table('mdb_workflow_revisions')->where('id', $batch->approved_revision_id)->update(['snapshot' => json_encode($snapshot)]);
        $this->getJson(route('mdb-workflow.synergee-json.download', [$batch, 'revision_sha256' => $batch->approvedRevision->sha256]))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_portable_snapshot_maps_coordinates_elevation_lengths_and_explicit_loads_without_database_import(): void
    {
        $transfer = app(SynergeeTransfer::class);
        $package = $transfer->read(json_encode(SynergeeSnapshot::package(), JSON_PRESERVE_ZERO_FRACTION));
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $report = $transfer->inspect($package, SynergeeSnapshot::gpx());
        $this->assertSame([], $report['issues'], json_encode($report['issues']));
        $this->assertCount(3, $report['payload']['tables']['InstSection']);
        $this->assertCount(4, $report['payload']['tables']['Node']);
        $this->assertSame(3.0, $report['payload']['tables']['Loads'][0]['Phase1Customers']);
        $this->assertSame(2.4, $report['payload']['tables']['Loads'][0]['Phase1Kw']);
        $this->assertSame('elevation_adjusted', $report['payload']['provenance'][0]['elevation_status']);
        $this->assertSame(0, $package['snapshot']['waypoints'][0]['elevation']);
        $this->assertSame('001', $package['snapshot']['waypoints'][0]['name']);
        $this->assertSame([], $writes);
    }

    public function test_local_json_generation_uses_first_e_for_both_first_pair_nodes(): void
    {
        $snapshot = SynergeeSnapshot::make();
        $transformer = &$snapshot['transformers'][0];
        $transformer['header'] += ['division' => 'Division', 'subdivision' => 'Subdivision', 'subdivision_code' => 'SD', 'service_category' => 'General Duty'];
        $row = ['transformer_id' => 1, 'section_id' => 1, 'pair_number' => 1, 'manually_verified' => true,
            'group_number' => '01', 'row_date' => '2026-10-09', 'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => '1',
            'conductors' => ['R' => 'DOG', 'Y' => '', 'B' => '', 'N' => 'DOG'], 'equipment_type' => 'pole',
            'pole_class' => 'concrete', 'pole_height' => 36, 'pole_height_unit' => 'ft', 'consumers' => [], 'pv_details' => []];
        $transformer['entry_rows'] = [
            $row + ['id' => 11, 'designation' => 'S', 'waypoint_reference' => '999', 'gpx_source_id' => null],
            $row + ['id' => 12, 'designation' => 'E', 'waypoint_reference' => '001', 'gpx_source_id' => 1],
        ];
        $transformer['sections'][0]['start_reference'] = '999';
        $transformer['sections'][0]['start_waypoint_id'] = null;
        $transformer['sections'][0]['start_source_file_id'] = null;
        $transformer['sections'][0]['end_reference'] = '001';
        $transformer['sections'][0]['end_waypoint_id'] = 1;
        $transformer['sections'][0]['original_entry']['start_coordinate_source'] = 'kmz';
        unset($transformer);
        $transfer = app(SynergeeTransfer::class);
        $package = $transfer->read(json_encode(SynergeeSnapshot::package($snapshot), JSON_PRESERVE_ZERO_FRACTION));
        $before = $package;
        $report = $transfer->inspect($package, SynergeeSnapshot::gpx());
        $this->assertSame([], $report['issues'], json_encode($report['issues']));
        $nodes = array_column($report['payload']['tables']['Node'], null, 'Description');
        $this->assertSame($nodes['001']['X'], $nodes['999']['X']);
        $this->assertSame($nodes['001']['Y'], $nodes['999']['Y']);
        $this->assertNotSame($nodes['001']['NodeId'], $nodes['999']['NodeId']);
        $this->assertSame(0.0, $report['payload']['tables']['InstSection'][2]['SectionLength_MUL']);
        $this->assertSame($before, $package);
    }

    public function test_local_json_generation_uses_frozen_registered_coordinates_only_for_first_s(): void
    {
        $snapshot = SynergeeSnapshot::make();
        $transformer = &$snapshot['transformers'][0];
        $transformer['header'] += ['division' => 'Division', 'subdivision' => 'Subdivision', 'subdivision_code' => 'SD', 'service_category' => 'General Duty',
            'registered_transformer_id' => 77, 'starting_pole_coordinates' => ['version' => 1, 'source' => 'registered_transformer',
                'registered_transformer_id' => 77, 'latitude' => 31.6999, 'longitude' => 73.4999, 'elevation' => 5]];
        $row = ['transformer_id' => 1, 'section_id' => 1, 'pair_number' => 1, 'manually_verified' => true,
            'group_number' => '01', 'row_date' => '2026-10-09', 'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => '1',
            'conductors' => ['R' => 'DOG', 'Y' => '', 'B' => '', 'N' => 'DOG'], 'equipment_type' => 'pole',
            'pole_class' => 'concrete', 'pole_height' => 36, 'pole_height_unit' => 'ft', 'consumers' => [], 'pv_details' => []];
        $transformer['entry_rows'] = [
            $row + ['id' => 11, 'designation' => 'S', 'waypoint_reference' => '999', 'gpx_source_id' => null],
            $row + ['id' => 12, 'designation' => 'E', 'waypoint_reference' => '001', 'gpx_source_id' => 1],
        ];
        $transformer['sections'][0]['start_reference'] = '999';
        $transformer['sections'][0]['start_waypoint_id'] = null;
        $transformer['sections'][0]['start_source_file_id'] = null;
        $transformer['sections'][0]['end_reference'] = '001';
        $transformer['sections'][0]['end_waypoint_id'] = 1;
        $registered = $transformer['header']['starting_pole_coordinates'];
        unset($transformer);
        $transfer = app(SynergeeTransfer::class);
        $package = $transfer->read(json_encode(SynergeeSnapshot::package($snapshot), JSON_PRESERVE_ZERO_FRACTION));
        $before = $package;
        $report = $transfer->inspect($package, SynergeeSnapshot::gpx());
        $this->assertSame([], $report['issues'], json_encode($report['issues']));
        $nodes = array_column($report['payload']['tables']['Node'], null, 'Description');
        $projected = app(ProjectionService::class)->projectMany([$registered], 32643)[0];
        $this->assertEqualsWithDelta($projected['x'], $nodes['999']['X'], 0.001);
        $this->assertEqualsWithDelta($projected['y'], $nodes['999']['Y'], 0.001);
        $this->assertNotSame($nodes['001']['X'], $nodes['999']['X']);
        $this->assertNotSame($nodes['001']['Y'], $nodes['999']['Y']);
        $this->assertGreaterThan(0, $report['payload']['tables']['InstSection'][2]['SectionLength_MUL']);
        $this->assertStringContainsString('captured registered transformer coordinates', json_encode($report['warnings']));
        $this->assertSame($before, $package);
        $this->assertSame($report['payload'], $transfer->inspect($package, SynergeeSnapshot::gpx())['payload']);
        foreach ($snapshot['transformers'][0]['entry_rows'] as &$entry) {
            $entry['identity_version'] = 2;
            $entry['composite_identifier'] = '01091026'.$entry['waypoint_reference'];
        }
        unset($entry);
        $snapshot['transformers'][0]['sections'][0]['original_entry']['start_survey_identifier'] = '01091026999';
        $snapshot['transformers'][0]['sections'][0]['original_entry']['end_survey_identifier'] = '01091026001';
        $identified = $transfer->read(json_encode(SynergeeSnapshot::package($snapshot), JSON_PRESERVE_ZERO_FRACTION));
        $identifiedReport = $transfer->inspect($identified, SynergeeSnapshot::gpx());
        $this->assertSame([], $identifiedReport['issues'], json_encode($identifiedReport['issues']));
        $identifiedNodes = array_column($identifiedReport['payload']['tables']['Node'], null, 'Description');
        $this->assertEqualsWithDelta($projected['x'], $identifiedNodes['01091026999']['X'], 0.001);
        $this->assertNotSame($identifiedNodes['01091026001']['X'], $identifiedNodes['01091026999']['X']);
    }

    public function test_missing_coordinates_unapproved_settings_and_unmapped_pv_block_generation(): void
    {
        $snapshot = SynergeeSnapshot::make();
        $snapshot['transformers'][0]['source_waypoint_id'] = null;
        $snapshot['configuration']['approved_by'] = null;
        $snapshot['transformers'][0]['pv_records'] = [['reference' => 'PV-test', 'installed_capacity_kw' => 5,
            'original_entry' => ['manually_verified' => true, 'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => '1']]];
        $report = app(SynergeeTransfer::class)->inspect(SynergeeSnapshot::package($snapshot));
        $codes = array_column($report['issues'], 'code');
        $this->assertContains('missing_transformer_source', $codes);
        $this->assertContains('unapproved_settings', $codes);
        $this->assertContains('pv_model_unmapped', $codes);
        $this->assertNull($report['payload']);
    }

    public function test_simple_mode_keeps_missing_engineering_as_warnings_in_offline_conversion(): void
    {
        $snapshot = SynergeeSnapshot::make();
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $snapshot['configuration']['settings']['frequency_hz'] = null;
        $snapshot['configuration']['settings']['conductor_library_revision'] = null;
        $snapshot['configuration']['settings']['transformer_library_revision'] = null;
        $snapshot['configuration']['approved_by'] = null;
        $snapshot['configuration']['approved_at'] = null;
        $snapshot['template']['approved_by'] = null;
        $snapshot['template']['approved_at'] = null;
        $snapshot['template']['metadata']['system_reference_schema'] = true;
        $report = app(SynergeeTransfer::class)->inspect(SynergeeSnapshot::package($snapshot));
        $this->assertSame([], $report['issues']);
        $this->assertContains('missing_electrical_setting', array_column($report['warnings'], 'code'));
        $this->assertTrue($report['payload']['settings']['simple_mdb_generation']);
        $snapshot['transformers'][0]['source_waypoint_id'] = null;
        $report = app(SynergeeTransfer::class)->inspect(SynergeeSnapshot::package($snapshot));
        $this->assertContains('missing_transformer_source', array_column($report['issues'], 'code'));
        $this->assertNull($report['payload']);
    }

    public function test_individual_scope_does_not_export_another_transformers_network_or_ignore_its_own_unverified_section(): void
    {
        $snapshot = SynergeeSnapshot::make();
        $other = $snapshot['transformers'][0];
        $other['id'] = 2;
        $other['code'] = 'OTHER-TRANSFORMER';
        $other['sections'] = [];
        $snapshot['transformers'][] = $other;
        $report = app(SynergeeTransfer::class)->inspect(SynergeeSnapshot::package($snapshot));
        $this->assertSame([], $report['issues']);
        $this->assertCount(1, $report['payload']['tables']['InstPrimaryTransformers']);
        $this->assertSame('T-SYNTHETIC', $report['payload']['tables']['InstPrimaryTransformers'][0]['UniqueDeviceId']);
        $snapshot['transformers'][0]['sections'][0]['original_entry']['manually_verified'] = false;
        $report = app(SynergeeTransfer::class)->inspect(SynergeeSnapshot::package($snapshot));
        $this->assertContains('unverified_survey_row', array_column($report['issues'], 'code'));
        $this->assertNull($report['payload']);
    }

    public function test_wrong_gpx_and_invalid_packages_are_rejected(): void
    {
        $transfer = app(SynergeeTransfer::class);
        $original = SynergeeSnapshot::package();
        foreach (['malformed', 'version', 'checksum', 'revision', 'template', 'scope'] as $case) {
            $package = $original;
            if ($case === 'version') {
                $package['version'] = 99;
            }
            if ($case === 'checksum') {
                $package['sha256'] = str_repeat('0', 64);
            }
            if ($case === 'revision') {
                $package['snapshot']['waypoints'][0]['elevation'] = 12;
            }
            if ($case === 'template') {
                $package['template_base64'] = base64_encode(str_repeat('x', 4096));
            }
            if ($case === 'scope') {
                $package['scope']['transformer_id'] = 999;
            }
            if (in_array($case, ['revision', 'template', 'scope'])) {
                unset($package['sha256']);
                $package['sha256'] = app(SnapshotService::class)->hash($package);
            }
            try {
                $transfer->read($case === 'malformed' ? '{invalid' : json_encode($package));
                $this->fail('Invalid package accepted: '.$case);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
        $this->expectException(ValidationException::class);
        $transfer->inspect($original, str_replace('73.5', '72.5', SynergeeSnapshot::gpx()));
    }

    public function test_local_routes_preview_missing_inputs_and_deny_unauthorized_users(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only local UI');
        }
        config(['mdb_workflow.offline_enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
        $this->get(route('mdb-workflow.offline-synergee.index'))->assertOk()->assertSee('JSON to SynerGEE');
        $snapshot = SynergeeSnapshot::make();
        $snapshot['configuration']['approved_by'] = null;
        $file = UploadedFile::fake()->createWithContent('synergee.json', json_encode(SynergeeSnapshot::package($snapshot)));
        $this->post(route('mdb-workflow.offline-synergee.preview'), ['file' => $file])->assertOk()->assertSee('require engineering approval')->assertDontSee('Generate and download SynerGEE MDB');
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $snapshot['configuration']['settings']['conductor_library_revision'] = null;
        $file = UploadedFile::fake()->createWithContent('simple-synergee.json', json_encode(SynergeeSnapshot::package($snapshot)));
        $this->post(route('mdb-workflow.offline-synergee.preview'), ['file' => $file])->assertOk()
            ->assertSee('ON — Simple MDB Generation')
            ->assertSee('Non-blocking review notes')
            ->assertSee('Generate and download SynerGEE MDB')
            ->assertDontSee('Mandatory corrections — resolve');
        $this->actingAs(User::factory()->create(['role' => UserRole::Surveyor]));
        $this->get(route('mdb-workflow.offline-synergee.index'))->assertForbidden();
        $this->post(route('mdb-workflow.offline-synergee.download'))->assertForbidden();
    }

    public function test_preview_explains_missing_frozen_type_and_uses_mapping_from_a_fresh_export(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only local UI');
        }
        config(['mdb_workflow.offline_enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
        $snapshot = SynergeeSnapshot::make();
        $snapshot['configuration']['revision'] = 3;
        $snapshot['configuration']['settings']['simple_mdb_generation'] = true;
        $snapshot['configuration']['settings']['engineering'] = ['transformer_types' => []];
        unset($snapshot['configuration']['settings']['mapping']['networks']['T-SYNTHETIC']['transformer_fields']['TransformerType']);
        $oldJson = json_encode(SynergeeSnapshot::package($snapshot));
        $this->post(route('mdb-workflow.offline-synergee.preview'), [
            'file' => UploadedFile::fake()->createWithContent('old.json', $oldJson),
        ])->assertOk()->assertSee('Missing from uploaded JSON')
            ->assertSee('Uploading the same file again retains its original settings.');

        $snapshot['configuration']['revision'] = 4;
        $snapshot['configuration']['settings']['engineering']['transformer_types'] = ['100' => '100 KVA'];
        $response = $this->post(route('mdb-workflow.offline-synergee.preview'), [
            'file' => UploadedFile::fake()->createWithContent('fresh.json', json_encode(SynergeeSnapshot::package($snapshot))),
        ])->assertOk()->assertSee('100 KVA')->assertDontSee('Missing from uploaded JSON');
        $this->assertSame('100 KVA', $response->viewData('payload')['tables']['InstPrimaryTransformers'][0]['TransformerType']);
        $check = collect($response->viewData('modelReadiness')['checks'])->firstWhere('label', 'Transformer equipment type');
        $this->assertSame(1, $check['complete']);
        $this->assertSame(0, $check['missing']);
        $oldReport = app(SynergeeTransfer::class)->inspect(app(SynergeeTransfer::class)->read($oldJson));
        $this->assertEmpty($oldReport['payload']['tables']['InstPrimaryTransformers'][0]['TransformerType'] ?? null);
    }

    public function test_genuine_mdb_is_written_and_read_back_without_any_local_approved_survey(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(AccessPowerShell::defaultPath())) {
            $this->markTestSkipped('Requires Windows DAO');
        }
        Storage::fake('local');
        config(['mdb_workflow.export.powershell' => AccessPowerShell::defaultPath()]);
        $path = app(SynergeeTransfer::class)->generate(SynergeeSnapshot::package());
        try {
            $this->assertFileExists($path);
            $this->assertGreaterThan(4096, filesize($path));
            $this->assertDatabaseCount('mdb_workflow_batches', 0);
            $this->assertDatabaseCount('mdb_workflow_revisions', 0);
            $this->assertCount(1, Storage::disk('local')->allFiles('synergee-offline'));
        } finally {
            unlink($path);
        }
    }

    public function test_recorded_phase_counts_export_to_a_genuine_mdb_with_verified_values(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(AccessPowerShell::defaultPath())) {
            $this->markTestSkipped('Requires Windows DAO');
        }
        Storage::fake('local');
        config(['mdb_workflow.export.powershell' => AccessPowerShell::defaultPath()]);
        $snapshot = SynergeeSnapshot::make();
        $snapshot['configuration']['revision'] = 1;
        $snapshot['configuration']['settings']['engineering'] = ['configuration_id' => 'POLE', 'transformer_types' => ['100' => '100KVA']];
        $snapshot['configuration']['load_assumptions'] = ['mode' => 'category_rates', 'single_phase_allocation' => 'recorded_or_equal', 'evidence' => 'Synthetic verified R/Y/B counts'];
        $section = &$snapshot['transformers'][0]['sections'][0];
        $section['phases'] = ['R', 'Y', 'B', 'N'];
        $section['conductors'] = ['R' => 'DOG', 'Y' => 'DOG', 'B' => 'DOG', 'N' => 'DOG'];
        $section['consumers'][0]['count'] = 10;
        $section['consumers'][0]['demand'] = null;
        $section['original_entry']['entry_rows'] = [['consumers' => ['rs' => 10], 'consumer_phases' => ['rs' => ['R' => 8, 'Y' => 1, 'B' => 1]]]];
        unset($section);
        $snapshot = app(EngineeringConfiguration::class)->apply($snapshot);
        $package = SynergeeSnapshot::package($snapshot);
        $transfer = app(SynergeeTransfer::class);
        $inspection = $transfer->inspect($package);
        $this->assertSame([], $inspection['issues']);
        $this->assertSame(16.0, $inspection['payload']['tables']['Loads'][0]['Phase1Kva']);
        $path = $transfer->generate($package);
        try {
            $this->assertFileExists($path);
            $this->assertGreaterThan(4096, filesize($path));
        } finally {
            unlink($path);
        }
    }

    public function test_generator_rechecks_package_integrity_instead_of_trusting_preview(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only generator');
        }
        $package = SynergeeSnapshot::package();
        $package['snapshot']['transformers'][0]['capacity_kva'] = 200;
        $this->expectException(ValidationException::class);
        app(SynergeeTransfer::class)->generate($package);
    }

    public function test_failed_download_does_not_flash_embedded_mdb_package_into_session(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only local UI');
        }
        config(['mdb_workflow.offline_enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
        $package = json_encode(SynergeeSnapshot::package(), JSON_THROW_ON_ERROR);
        $this->mock(SynergeeTransfer::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn([]);
            $mock->shouldReceive('generate')->once()->andThrow(ValidationException::withMessages(['file' => 'MDB writer test failure.']));
        });
        $this->post(route('mdb-workflow.offline-synergee.download'), ['package' => $package])
            ->assertRedirect(route('mdb-workflow.offline-synergee.index'))
            ->assertSessionHasErrors('file')
            ->assertSessionMissing('_old_input.package');
        $this->assertLessThan(10000, strlen(serialize(session()->all())));
    }

    public function test_pdf_pairs_and_pv_cannot_be_silently_left_out_of_the_engineering_network(): void
    {
        $snapshot = SynergeeSnapshot::make();
        $rows = DataEntrySnapshot::make()['transformers'][0]['entry_rows'];
        foreach ($rows as $index => &$row) {
            $row += ['transformer_id' => 1, 'section_id' => 1, 'gpx_source_id' => 1, 'source_pdf_id' => 2, 'source_page' => 1, 'source_row' => (string) ($index + 1), 'original_entry' => []];
            $row['conductors'] = ['R' => 'DOG', 'Y' => '', 'B' => '', 'N' => 'DOG'];
            $row['consumers'] = array_fill_keys(['rs', 'rl', 'sc', 'lc', 'si', 'li', 'pb', 'ag', 'st', 'pv'], 0);
            $row['consumers']['rs'] = $index ? 3 : 0;
            $row['pv_details'] = [];
        }
        unset($row);
        $snapshot['transformers'][0]['entry_rows'] = $rows;
        $snapshot['transformers'][0]['header'] += ['division' => 'D', 'subdivision' => 'SD', 'subdivision_code' => '01', 'service_category' => 'General Duty'];
        $snapshot['configuration']['settings']['mapping']['networks']['T-SYNTHETIC']['source_sections'][1]['to'] = 'wp:2';
        $transfer = app(SynergeeTransfer::class);
        $report = $transfer->inspect(SynergeeSnapshot::package($snapshot));
        $this->assertSame([], $report['issues'], json_encode($report['issues']));
        $snapshot['transformers'][0]['entry_rows'][0]['section_id'] = null;
        $snapshot['transformers'][0]['entry_rows'][0]['pv_details'] = [['reference' => 'PV-missing', 'installed_capacity_kw' => 5]];
        $report = $transfer->inspect(SynergeeSnapshot::package($snapshot));
        $this->assertContains('unmapped_pdf_pair', array_column($report['issues'], 'code'));
        $this->assertContains('unmapped_pdf_pv', array_column($report['issues'], 'code'));
        $this->assertNull($report['payload']);
    }
}
