<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\Mdb\Template;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\TransformerMdbProject;
use App\Models\User;
use App\Services\DriveStorage\FileStorage;
use App\Services\GpxWaypointService;
use App\Services\Mdb\SchemaTemplate;
use App\Services\TransformerMdbNetworkService;
use App\Services\TransformerMdbWriter;
use App\Services\UtmProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransformerMdbBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Feeder $feeder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $organization = Organization::create(['name' => 'Internal', 'type' => 'internal', 'status' => 'active']);
        $this->user = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::SuperAdmin]);
        $project = Project::create(['code' => 'P1', 'name' => 'Project', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C1', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D1', 'name' => 'Division']);
        $subdivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD1', 'name' => 'Subdivision']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subdivision->id, 'code' => 'G1', 'name' => 'Grid']);
        $this->feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id,
            'sub_division_id' => $subdivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => '000412', 'feeder_name' => 'Feeder',
            'total_transformers' => 0, 'baseline_pending' => true, 'status' => 'active']);
        $this->actingAs($this->user);
    }

    private function payload(): array
    {
        $date = today()->toDateString();

        return ['revision' => 0, 'feeder_id' => $this->feeder->id, 'transformer_code' => 'T-0001', 'survey_date' => $date,
            'header' => ['substation' => 'Grid', 'capacity_kva' => 100, 'inspectors' => 'Inspector'], 'solar' => [], 'remarks' => 'Paper survey',
            'rows' => [
                ['se' => 'S', 'date' => $date, 'gps_waypoint' => '0001', 'phase' => 'R-Y-B', 'conductor_r' => 'A', 'conductor_y' => 'W', 'conductor_b' => 'GN', 'conductor_neutral' => 'A', 'consumers' => []],
                ['se' => 'E', 'date' => $date, 'gps_waypoint' => '0002', 'consumers' => ['rs' => 3, 'rl' => 1]],
            ],
            'export_settings' => ['utm_zone' => 43, 'frequency' => 50, 'nominal_kv' => 11, 'transformer_waypoints' => '0001',
                'transformer_latitude' => 30.464656, 'transformer_longitude' => 71.917033, 'transformer_type' => '100 KVA',
                'configuration_id' => '12.5/7.2 kV cross arm C2-2', 'phase_spacing_cm' => 121.9, 'neutral_spacing_cm' => 91.4,
                'conductor_height_m' => 9.1, 'consumer_kva' => ['rs' => 3, 'rl' => 6], 'blank_consumers_zero' => true, 'engineering_reviewed' => true]];
    }

    private function gpx(): string
    {
        return '<gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1"><wpt lat="30.464641" lon="71.917066"><name>0002</name><time>2025-08-23T08:15:32Z</time></wpt></gpx>';
    }

    private function saveProject(): TransformerMdbProject
    {
        $this->post(route('mdb-builder.store'), ['payload' => json_encode($this->payload()), 'gpx' => UploadedFile::fake()->createWithContent('survey.gpx', $this->gpx())])->assertSessionHasNoErrors()->assertRedirect();

        return TransformerMdbProject::firstOrFail();
    }

    public function test_automatic_drive_registration_tracks_workspace_and_keeps_failed_upload_recoverable(): void
    {
        config(['drive_storage.enabled' => true]);
        Queue::fake();
        $project = $this->saveProject();
        $archive = $project->gpxDriveStoredFile;
        $this->assertNotNull($archive);
        $this->assertSame($project->transformer_code, $archive->context['transformer_code']);
        $this->assertSame($project->id, $archive->context['transformer_workspace_id']);
        try {
            app(FileStorage::class)->transfer($archive->id);
            $this->fail('Expected missing storage account.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed', $archive->fresh()->status);
        $this->get(route('mdb-builder.source', [$project, 'gpx']))->assertOk()->assertDownload('survey.gpx');
        Storage::disk('local')->assertExists($project->gpx_path);
        foreach (Storage::disk('local')->files('drive-storage/work') as $temporary) {
            Storage::disk('local')->delete($temporary);
        }
    }

    public function test_manual_survey_saves_uploads_matches_gpx_and_preserves_source_values(): void
    {
        $project = $this->saveProject();
        $this->assertSame('0001', $project->rows[0]['gps_waypoint']);
        $this->assertSame('0002', $project->gpx_waypoints[0]['name']);
        $this->assertSame(1, $project->revision);
        Storage::disk('local')->assertExists($project->gpx_path);
        $this->get(route('mdb-builder.edit', $project))->assertOk()->assertSee('Paper survey rows')->assertSee('0002');
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('Network ready to export');
        $network = app(TransformerMdbNetworkService::class)->build($project);
        $this->assertSame(4, $network['node_count']);
        $this->assertSame(3, $network['section_count']);
        $this->assertSame('ANT', $network['tables']['InstSection'][2]['PhaseConductorId']);
        $this->assertSame('WASP', $network['tables']['InstSection'][2]['PhaseConductor2Id']);
        $this->assertSame('GNAT', $network['tables']['InstSection'][2]['PhaseConductor3Id']);
        $this->assertEqualsWithDelta(5, $network['tables']['Loads'][0]['Phase1Kva'], 0.001);
        $this->assertEqualsWithDelta(4 / 3, $network['tables']['Loads'][0]['Phase1Customers'], 0.001);
        $this->assertGreaterThan(3, $network['pairs'][0]['length']);
        $this->assertSame('0002', json_decode($network['survey_audit']['rows'][1]['SourceJson'], true)['gps_waypoint']);
        $this->assertArrayNotHasKey('SurveyRows', $network['tables']);
        $this->assertDatabaseCount('survey_daily_entries', 0);
        $this->assertDatabaseCount('mdb_daily_entries', 0);
    }

    public function test_stale_updates_do_not_replace_survey_or_files(): void
    {
        $project = $this->saveProject();
        $path = $project->gpx_path;
        $payload = $this->payload();
        $payload['transformer_code'] = 'WRONG';
        $this->put(route('mdb-builder.update', $project), ['payload' => json_encode($payload), 'gpx' => UploadedFile::fake()->createWithContent('replacement.gpx', $this->gpx())])->assertSessionHasErrors('revision');
        $this->assertSame('T-0001', $project->fresh()->transformer_code);
        $this->assertSame($path, $project->fresh()->gpx_path);
        $this->assertCount(1, Storage::disk('local')->allFiles('mdb-builder/sources'));
    }

    public function test_android_survey_is_copied_without_changing_its_revision(): void
    {
        $team = SurveyTeam::create(['project_id' => $this->feeder->project_id, 'code' => 'ST', 'name' => 'Team', 'status' => 'active']);
        $payload = $this->payload();
        $survey = FieldSurvey::create(['client_uuid' => (string) Str::uuid(), 'collected_by' => $this->user->id, 'survey_team_id' => $team->id,
            'feeder_id' => $this->feeder->id, 'transformer_code' => $payload['transformer_code'], 'survey_date' => today(), 'status' => 'submitted',
            'revision' => 7, 'payload_hash' => str_repeat('a', 64), 'header' => $payload['header'], 'rows' => $payload['rows'], 'solar' => [], 'reference_snapshot' => []]);
        $this->get(route('mdb-builder.create', ['source' => $survey->id]))->assertOk()->assertSee('T-0001');
        $payload['source_field_survey_id'] = $survey->id;
        $this->post(route('mdb-builder.store'), ['payload' => json_encode($payload)])->assertSessionHasNoErrors();
        $this->assertSame($survey->id, TransformerMdbProject::first()->source_field_survey_id);
        $this->assertSame(7, $survey->fresh()->revision);
    }

    public function test_only_super_admin_can_access_mdb_workspaces_and_downloads(): void
    {
        $project = $this->saveProject();
        foreach ([UserRole::MdbTeamUser, UserRole::ProjectManager, UserRole::SurveyTeamLeader, UserRole::ManagementViewer, UserRole::MdbProcessingUser] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            if ($role !== UserRole::MdbProcessingUser) {
                $this->get(route('dashboard'))->assertOk()->assertDontSee('Create Transformer MDB');
            }
            foreach (['index' => [], 'create' => [], 'edit' => [$project], 'preview' => [$project], 'source' => [$project, 'gpx']] as $action => $parameters) {
                $this->get(route('mdb-builder.'.$action, $parameters))->assertForbidden();
            }
            $this->post(route('mdb-builder.store'), ['payload' => json_encode($this->payload())])->assertForbidden();
            $this->put(route('mdb-builder.update', $project), ['payload' => json_encode($this->payload())])->assertForbidden();
            $this->post(route('mdb-builder.export', $project), ['revision' => 1])->assertForbidden();
        }
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertSee('Create Transformer MDB');
        $this->get(route('mdb-builder.preview', $project))->assertOk();
    }

    public function test_missing_waypoints_block_preview_instead_of_creating_wrong_nodes(): void
    {
        $project = $this->saveProject();
        $rows = $project->rows;
        $rows[1]['gps_waypoint'] = 'MISSING';
        $project->update(['rows' => $rows]);
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('was not found')->assertDontSee('Create and download MDB');
    }

    public function test_disconnected_pairs_and_blank_counts_are_rejected(): void
    {
        $project = $this->saveProject();
        $rows = $project->rows;
        $rows[0]['gps_waypoint'] = 'OTHER';
        $rows[0]['latitude'] = 30.464656;
        $rows[0]['longitude'] = 71.917033;
        $project->update(['rows' => $rows]);
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('disconnected');
        $project->update(['rows' => $this->payload()['rows'], 'export_settings' => array_replace($project->export_settings, ['blank_consumers_zero' => false])]);
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('enter all E row consumer counts');
    }

    public function test_gpx_rejects_entities_and_resolves_reused_identifiers_by_date(): void
    {
        $bad = '<!DOCTYPE gpx [<!ENTITY secret SYSTEM "file:///etc/passwd">]><gpx xmlns="http://www.topografix.com/GPX/1/1"/>';
        $this->post(route('mdb-builder.store'), ['payload' => json_encode($this->payload()), 'gpx' => UploadedFile::fake()->createWithContent('bad.gpx', $bad)])->assertSessionHasErrors('gpx');
        $waypoints = [['name' => '001', 'date' => '2025-01-01', 'latitude' => 30, 'longitude' => 72], ['name' => '001', 'date' => '2025-01-02', 'latitude' => 31, 'longitude' => 72]];
        $this->assertSame(31, app(GpxWaypointService::class)->resolve($waypoints, '001', '2025-01-02')['latitude']);
        $this->expectException(ValidationException::class);
        app(GpxWaypointService::class)->resolve($waypoints, '001', null);
    }

    public function test_projection_matches_the_sample_gpx_and_mdb_metre_coordinates(): void
    {
        $xy = app(UtmProjection::class)->project(30.464656, 71.917033, 42);
        $this->assertEqualsWithDelta(780080, $xy['X'], 1);
        $this->assertEqualsWithDelta(3373892, $xy['Y'], 1);
        $zone43 = app(UtmProjection::class)->project(30.464656, 71.917033, 43);
        // Independent PROJ/pyproj reference values; catches wrong central meridian.
        $this->assertEqualsWithDelta(203980.0444377457, $zone43['X'], 0.01);
        $this->assertEqualsWithDelta(3374315.132612462, $zone43['Y'], 0.01);
    }

    private function simpleConfiguration(array $settings = [], array $loads = []): Configuration
    {
        return Configuration::create(['project_id' => $this->feeder->project_id, 'epsg' => null, 'revision' => 1,
            'settings' => array_replace_recursive(['simple_mdb_generation' => true, 'engineering' => [], 'mapping' => ['table_values' => []]], $settings),
            'load_assumptions' => $loads, 'approved_by' => null, 'approved_at' => null]);
    }

    public function test_simple_mode_uses_central_draft_and_leaves_unknown_engineering_values_blank(): void
    {
        $project = $this->saveProject();
        $configuration = $this->simpleConfiguration();
        $network = app(TransformerMdbNetworkService::class)->build($project, $configuration);
        $this->assertTrue($network['simple_mdb_generation']);
        $this->assertNull($network['tables']['SAI_Control'][0]['Frequency']);
        $this->assertNull($network['tables']['SAI_Control'][0]['ProjectionFile']);
        $this->assertNull($network['tables']['InstFeeders'][0]['NominalKvll']);
        $this->assertNull($network['tables']['InstPrimaryTransformers'][0]['TransformerType']);
        $this->assertNull($network['tables']['InstSection'][0]['PhaseConductorId']);
        $this->assertSame('', $network['tables']['InstSection'][0]['SectionPhases']);
        $this->assertSame('', $network['tables']['InstSection'][1]['SectionPhases']);
        $this->assertNull($network['tables']['InstSection'][2]['PhaseToPhaseSpacing_MUL']);
        $this->assertNull($network['tables']['InstSection'][2]['SectionLength_MUL']);
        $this->assertNull($network['tables']['Node'][3]['X']);
        $this->assertNull($network['tables']['Node'][2]['IsPadMountGear']);
        $this->assertNull($network['tables']['Node'][3]['IsPadMountGear']);
        $this->assertNull($network['tables']['Loads'][0]['Phase1Kva']);
        $this->assertNull($network['tables']['Loads'][0]['Phase1Customers']);
        $this->assertSame(100, $network['survey_audit']['header']['header']['capacity_kva']);
        $this->assertFalse($network['survey_audit']['header']['export_settings']['engineering_reviewed']);
        $this->assertSame(3, json_decode($network['survey_audit']['rows'][1]['SourceJson'], true)['consumers']['rs']);
        $this->assertNull($configuration->fresh()->approved_by);
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('Simple MDB Generation is ON')->assertSee('CRS not configured');
    }

    public function test_simple_mode_can_save_missing_engineering_inputs_but_retains_numeric_validation(): void
    {
        $this->simpleConfiguration();
        $payload = $this->payload();
        $payload['export_settings'] = collect($payload['export_settings'])->only(['transformer_waypoints', 'transformer_latitude', 'transformer_longitude'])->all();
        $payload['header']['capacity_kva'] = null;
        $this->post(route('mdb-builder.store'), ['payload' => json_encode($payload), 'gpx' => UploadedFile::fake()->createWithContent('survey.gpx', $this->gpx())])->assertSessionHasNoErrors();
        $project = TransformerMdbProject::firstOrFail();
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('Network ready to export');
        $payload['revision'] = $project->revision;
        $payload['export_settings']['nominal_kv'] = 'invalid';
        $this->put(route('mdb-builder.update', $project), ['payload' => json_encode($payload)])->assertSessionHasErrors('export_settings.nominal_kv');
    }

    public function test_simple_mode_uses_explicit_category_rates_without_fabricating_approval(): void
    {
        $project = $this->saveProject();
        $configuration = $this->simpleConfiguration(['frequency_hz' => 60, 'nominal_voltage_kv' => 11, 'engineering' => ['blank_consumers_zero' => true]],
            ['mode' => 'category_rates', 'rates' => ['rs' => ['kva' => 3, 'power_factor' => 1], 'rl' => ['kva' => 6, 'power_factor' => 1]]]);
        $network = app(TransformerMdbNetworkService::class)->build($project, $configuration);
        $this->assertSame(60, $network['tables']['SAI_Control'][0]['Frequency']);
        $this->assertSame(11.0, $network['tables']['InstFeeders'][0]['NominalKvll']);
        $this->assertSame(2, $network['tables']['Loads'][0]['Phase1Customers']);
        $this->assertSame(9.0, $network['tables']['Loads'][0]['Phase1Kva']);
        $this->assertSame(0.0, $network['tables']['Loads'][0]['Phase1Kvar']);
        $this->assertNull($configuration->fresh()->approved_by);
    }

    public function test_simple_mode_keeps_network_connectivity_and_waypoint_validation(): void
    {
        $project = $this->saveProject();
        $configuration = $this->simpleConfiguration();
        $rows = $project->rows;
        $rows[0]['gps_waypoint'] = 'OTHER';
        $rows[0]['latitude'] = 30.464656;
        $rows[0]['longitude'] = 71.917033;
        $project->update(['rows' => $rows]);
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('disconnected');
        $rows = $this->payload()['rows'];
        $rows[1]['gps_waypoint'] = 'MISSING';
        $project->update(['rows' => $rows]);
        $this->get(route('mdb-builder.preview', $project))->assertOk()->assertSee('was not found');
        $this->assertTrue($configuration->fresh()->settings['simple_mdb_generation']);
    }

    public function test_simple_mode_keeps_unrecorded_phase_blank_without_allocating_consumer_demand(): void
    {
        $project = $this->saveProject();
        $configuration = $this->simpleConfiguration(['engineering' => ['blank_consumers_zero' => true]],
            ['mode' => 'category_rates', 'rates' => array_fill_keys(TransformerMdbNetworkService::CONSUMERS, ['kva' => 3, 'power_factor' => 1])]);
        $rows = $project->rows;
        $rows[0]['phase'] = null;
        $project->update(['rows' => $rows]);
        $network = app(TransformerMdbNetworkService::class)->build($project, $configuration);
        $this->assertSame('', $network['tables']['InstSection'][2]['SectionPhases']);
        $this->assertNull($network['tables']['Loads'][0]['Phase1Kva']);
        $this->assertNull($network['tables']['Loads'][0]['Phase1Customers']);
        $this->assertSame(4, $network['node_count']);
        $this->assertSame(3, $network['section_count']);
        $rows[0]['phase'] = 'invalid';
        $project->update(['rows' => $rows]);
        $this->expectException(ValidationException::class);
        app(TransformerMdbNetworkService::class)->build($project, $configuration);
    }

    public function test_full_mode_still_requires_engineering_approval_and_cannot_be_overridden_by_request(): void
    {
        $project = $this->saveProject();
        $this->simpleConfiguration(['simple_mdb_generation' => false]);
        $this->post(route('mdb-builder.export', $project), ['revision' => $project->revision, 'simple_mdb_generation' => true])->assertSessionHasErrors('engineering');
        $payload = $this->payload();
        $payload['revision'] = $project->revision;
        $payload['export_settings']['nominal_kv'] = null;
        $this->put(route('mdb-builder.update', $project), ['payload' => json_encode($payload), 'simple_mdb_generation' => true])->assertSessionHasErrors('export_settings.nominal_kv');
    }

    public function test_mode_change_during_export_prevents_releasing_stale_file(): void
    {
        $project = $this->saveProject();
        $configuration = $this->simpleConfiguration();
        $path = Storage::disk('local')->path('stale-simple.mdb');
        $this->mock(TransformerMdbWriter::class, function ($mock) use ($configuration, $path) {
            $mock->shouldReceive('write')->once()->andReturnUsing(function () use ($configuration, $path) {
                File::put($path, 'temporary MDB');
                $configuration->update(['settings' => ['simple_mdb_generation' => false], 'revision' => 2]);

                return $path;
            });
        });
        $this->post(route('mdb-builder.export', $project), ['revision' => $project->revision])->assertSessionHasErrors('engineering');
        $this->assertFileDoesNotExist($path);
    }

    public function test_simple_mode_exports_with_the_explicit_unapproved_registered_schema_template(): void
    {
        $project = $this->saveProject();
        $template = app(SchemaTemplate::class)->ensure($this->user);
        $configuration = $this->simpleConfiguration(['template_id' => $template->id]);
        $network = app(TransformerMdbNetworkService::class)->build($project, $configuration);
        $this->assertSame($template->id, $network['engineering_template_id']);
        $this->assertSame($template->sha256, $network['engineering_template_sha256']);
        $this->assertNull($template->approved_by);
        $path = Storage::disk('local')->path('registered-schema-lifecycle.mdb');
        // The native tests cover MDB bytes; this isolates the post-write release checks.
        $this->mock(TransformerMdbWriter::class, function ($mock) use ($template, $path) {
            $mock->shouldReceive('write')->once()->andReturnUsing(function ($network) use ($template, $path) {
                $this->assertSame($template->id, $network['engineering_template_id']);
                File::put($path, 'registered schema lifecycle artifact');

                return $path;
            });
        });
        try {
            $this->post(route('mdb-builder.export', $project), ['revision' => $project->revision])->assertOk()->assertDownload('Feeder-T-0001.mdb');
            $this->assertNull($template->fresh()->approved_by);
        } finally {
            File::delete($path);
        }
    }

    public function test_modes_produce_identical_table_values_with_complete_engineering_configuration(): void
    {
        $project = $this->saveProject();
        $template = Template::create(['code' => 'mode-comparison', 'version' => '1', 'disk' => 'local', 'path' => 'unused.mdb',
            'sha256' => hash_file('sha256', resource_path('mdb/synergee-empty.mdb')), 'synergee_version' => 'test',
            'approved_by' => $this->user->id, 'approved_at' => now('UTC'), 'active' => true,
            'metadata' => ['equipment_references' => ['conductors' => ['DOG', 'ANT', 'WASP', 'GNAT'], 'transformers' => ['100 KVA']]]]);
        $mapping = json_decode(file_get_contents(resource_path('mdb/defaults.json')), true);
        $mapping['SAI_Control']['Frequency'] = 50;
        $mapping['SAI_Control']['ProjectionFile'] = 'WGS 1984 UTM Zone 43N';
        $mapping['SAI_Control']['ProjectionWKT'] = '';
        $configuration = Configuration::create(['project_id' => $this->feeder->project_id, 'epsg' => 32643, 'revision' => 1,
            'settings' => ['simple_mdb_generation' => false, 'frequency_hz' => 50, 'nominal_voltage_kv' => 11, 'template_id' => $template->id,
                'mapping' => ['table_values' => $mapping], 'engineering' => ['configuration_id' => '12.5/7.2 kV cross arm C2-2',
                    'supply_conductor_id' => 'DOG', 'secondary_conductor_id' => 'ANT', 'transformer_types' => ['100' => '100 KVA'],
                    'phase_spacing_cm' => 121.9, 'neutral_spacing_cm' => 91.4, 'conductor_height_m' => 9.1, 'blank_consumers_zero' => true]],
            'load_assumptions' => ['mode' => 'category_rates', 'method' => 'Synthetic fixture', 'evidence' => 'fixture',
                'rates' => array_fill_keys(TransformerMdbNetworkService::CONSUMERS, ['kva' => 3, 'power_factor' => 1])],
            'approved_by' => $this->user->id, 'approved_at' => now('UTC')]);
        $full = app(TransformerMdbNetworkService::class)->build($project, $configuration);
        $configuration->settings = array_replace($configuration->settings, ['simple_mdb_generation' => true]);
        $simple = app(TransformerMdbNetworkService::class)->build($project, $configuration);
        $this->assertSame($full['tables'], $simple['tables']);
    }

    public function test_native_simple_mode_exports_reference_schema_with_null_engineering_values(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(config('mdb.powershell'))) {
            $this->markTestSkipped('Native MDB integration requires the Windows Access Database Engine.');
        }
        $project = $this->saveProject();
        $this->simpleConfiguration();
        $response = $this->post(route('mdb-builder.export', $project), ['revision' => $project->revision])->assertOk()->assertDownload('Feeder-T-0001.mdb');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $process = new Process([config('mdb.powershell'), '-NoProfile', '-NonInteractive', '-Command',
                '$c=New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.12.0;Data Source='.$path.';Mode=Read;");$c.Open();$cmd=$c.CreateCommand();$cmd.CommandText="SELECT COUNT(*) FROM Node WHERE X IS NULL AND Y IS NULL";Write-Output $cmd.ExecuteScalar();$cmd.CommandText="SELECT COUNT(*) FROM Loads WHERE Phase1Kva IS NULL AND Phase1Customers IS NULL";Write-Output $cmd.ExecuteScalar();$t=$c.GetSchema("Tables");Write-Output @($t.Rows | Where-Object { $_.TABLE_TYPE -eq "TABLE" -and -not $_.TABLE_NAME.StartsWith("MSys") }).Count;$c.Close();']);
            $process->mustRun();
            $this->assertSame(['4', '1', '50'], preg_split('/\s+/', trim($process->getOutput())));
        } finally {
            File::deleteDirectory(dirname($path));
        }
    }

    public function test_native_mdb_writer_creates_readable_database_with_matching_rows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(config('mdb.powershell'))) {
            $this->markTestSkipped('Native MDB integration requires the Windows Access Database Engine.');
        }
        $project = $this->saveProject();
        // Synthetic engineering approval for this integration test, never production defaults.
        $templateBytes = file_get_contents(resource_path('mdb/synergee-empty.mdb'));
        Storage::disk('local')->put('native-template.mdb', $templateBytes);
        $template = Template::create(['code' => 'native-fixture', 'version' => '1', 'disk' => 'local', 'path' => 'native-template.mdb',
            'sha256' => hash('sha256', $templateBytes), 'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'approved_by' => $this->user->id, 'approved_at' => now('UTC'), 'active' => true,
            'metadata' => ['equipment_references' => ['conductors' => ['DOG', 'ANT', 'WASP', 'GNAT'], 'transformers' => ['100 KVA'], 'configurations' => ['12.5/7.2 kV cross arm C2-2']]]]);
        $mapping = json_decode(file_get_contents(resource_path('mdb/defaults.json')), true);
        $mapping['SAI_Control']['Frequency'] = 50;
        $mapping['SAI_Control']['ProjectionFile'] = 'WGS 1984 UTM Zone 42N';
        Configuration::create(['project_id' => $this->feeder->project_id, 'epsg' => 32642,
            'settings' => ['frequency_hz' => 50, 'nominal_voltage_kv' => 11, 'template_id' => $template->id, 'mapping' => ['table_values' => $mapping],
                'engineering' => ['configuration_id' => '12.5/7.2 kV cross arm C2-2', 'supply_conductor_id' => 'DOG', 'secondary_conductor_id' => 'ANT',
                    'transformer_types' => ['100' => '100 KVA'], 'phase_spacing_cm' => 121.9, 'neutral_spacing_cm' => 91.4, 'conductor_height_m' => 9.1, 'blank_consumers_zero' => true]],
            'load_assumptions' => ['mode' => 'category_rates', 'method' => 'Synthetic integration fixture', 'evidence' => 'fixture',
                'rates' => array_fill_keys(TransformerMdbNetworkService::CONSUMERS, ['kva' => 3, 'power_factor' => 1])],
            'approved_by' => $this->user->id, 'approved_at' => now('UTC')]);
        $response = $this->post(route('mdb-builder.export', $project), ['revision' => $project->revision])->assertOk()->assertDownload('Feeder-T-0001.mdb');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $this->assertFileExists($path);
            $process = new Process([config('mdb.powershell'), '-NoProfile', '-NonInteractive', '-Command',
                '$c=New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.12.0;Data Source='.$path.';Mode=Read;");$c.Open();$cmd=$c.CreateCommand();$cmd.CommandText="SELECT COUNT(*) FROM Node";Write-Output $cmd.ExecuteScalar();$cmd.CommandText="SELECT TOP 1 UniqueDeviceId FROM InstPrimaryTransformers";Write-Output $cmd.ExecuteScalar();$t=$c.GetSchema("Tables");Write-Output @($t.Rows | Where-Object { $_.TABLE_TYPE -eq "TABLE" -and -not $_.TABLE_NAME.StartsWith("MSys") }).Count;$c.Close();']);
            $process->mustRun();
            $this->assertSame(['4', 'T-0001', '50'], preg_split('/\s+/', trim($process->getOutput())));
        } finally {
            File::deleteDirectory(dirname($path));
        }
    }
}
