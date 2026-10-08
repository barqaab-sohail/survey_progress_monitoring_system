<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\GridStation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\TransformerMdbProject;
use App\Models\User;
use App\Services\GpxWaypointService;
use App\Services\TransformerMdbNetworkService;
use App\Services\UtmProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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
        $this->assertSame('0002', json_decode($network['tables']['SurveyRows'][1]['SourceJson'], true)['gps_waypoint']);
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

    public function test_native_mdb_writer_creates_readable_database_with_matching_rows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(config('mdb.powershell'))) {
            $this->markTestSkipped('Native MDB integration requires the Windows Access Database Engine.');
        }
        $project = $this->saveProject();
        $response = $this->post(route('mdb-builder.export', $project), ['revision' => $project->revision])->assertOk()->assertDownload('Feeder-T-0001.mdb');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $this->assertFileExists($path);
            $process = new Process([config('mdb.powershell'), '-NoProfile', '-NonInteractive', '-Command',
                '$c=New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.12.0;Data Source='.$path.';Mode=Read;");$c.Open();$cmd=$c.CreateCommand();$cmd.CommandText="SELECT COUNT(*) FROM Node";Write-Output $cmd.ExecuteScalar();$cmd.CommandText="SELECT TOP 1 UniqueDeviceId FROM InstPrimaryTransformers";Write-Output $cmd.ExecuteScalar();$cmd.CommandText="SELECT COUNT(*) FROM SurveyRows";Write-Output $cmd.ExecuteScalar();$c.Close();']);
            $process->mustRun();
            $this->assertSame(['4', 'T-0001', '2'], preg_split('/\s+/', trim($process->getOutput())));
        } finally {
            File::deleteDirectory(dirname($path));
        }
    }
}
