<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\FieldSurvey;
use App\Models\GridStation;
use App\Models\Mdb\Configuration;
use App\Models\MdbTeam;
use App\Models\MobileDeviceToken;
use App\Models\MobileGpxSource;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\AccessPowerShell;
use App\Services\Mdb\SchemaTemplate;
use App\Services\Mdb\SynergeeTransfer;
use App\Services\MobileGpxWorkflow;
use App\Services\MobileSurveySnapshot;
use Database\Seeders\MdbWorkflowPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileGpxWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;

    private User $admin;

    private Organization $organization;

    private Project $project;

    private SurveyTeam $team;

    private Feeder $feeder;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->organization = Organization::create(['name' => 'Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $this->leader = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::MobileSurveyUser]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::SuperAdmin]);
        $this->project = Project::create(['code' => 'FIELD', 'name' => 'Field project', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $this->project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $this->project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Sub division']);
        $grid = GridStation::create(['project_id' => $this->project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G', 'name' => 'Substation']);
        $this->feeder = Feeder::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => '0012', 'feeder_name' => 'Assigned feeder', 'total_transformers' => 0, 'baseline_pending' => true, 'status' => 'active']);
        $this->team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'ST', 'name' => 'Survey Team 01', 'group_code' => '02', 'gpx_group_code' => 'A', 'status' => 'active']);
        $this->team->members()->attach($this->leader->id, ['is_leader' => true]);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->team->id, 'assigned_by' => $this->admin->id, 'start_date' => today()->subDay(), 'status' => 'active']);
        $this->token = $this->tokenFor($this->leader);
        $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function tokenFor(User $user): string
    {
        $plain = bin2hex(random_bytes(64));
        MobileDeviceToken::create(['user_id' => $user->id, 'device_name' => 'Test phone', 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(30)]);

        return $plain;
    }

    private function payload(string $status = 'submitted'): array
    {
        $startId = (string) Str::uuid();
        $endId = (string) Str::uuid();

        return [
            'client_uuid' => (string) Str::uuid(), 'base_revision' => 0,
            'survey_team_id' => $this->team->id, 'feeder_id' => $this->feeder->id,
            'transformer_id' => null, 'transformer_code' => 'B2308',
            'survey_date' => today()->toDateString(), 'status' => $status,
            'header' => ['substation' => 'Substation', 'capacity_kva' => 50, 'inspectors' => 'Field inspector', 'location' => 'Street 1', 'mounting' => 'D.Pole', 'duty' => 'General Duty'],
            'rows' => [['row_id' => $startId, 'se' => 'S', 'group' => '02', 'date' => today()->toDateString(), 'gps_waypoint' => '0008', 'phase' => '3', 'conductor_r' => 'A', 'equipment_type' => 'TR', 'pole_class' => 'PCO', 'pole_height_ft' => 36, 'consumers' => ['rs' => 5, 'lc' => 1], 'intersection' => true],
                ['row_id' => $endId, 'se' => 'E', 'group' => '02', 'date' => today()->toDateString(), 'gps_waypoint' => '0009', 'phase' => 'R', 'conductor_r' => 'A', 'equipment_type' => 'TR', 'pole_class' => 'PCO', 'pole_height_ft' => 0]],
            'solar' => [['row_id' => $startId, 'consumer_reference' => '0000123', 'installed_pv_kw' => 5.5, 'remarks' => 'Rooftop']], 'remarks' => 'Field visit',
        ];
    }

    private function savedSurvey(string $code = 'TR-025'): FieldSurvey
    {
        $data = $this->payload();
        $data['transformer_code'] = $code;
        $data['rows'][0] = array_replace($data['rows'][0], ['group' => '01', 'date' => '2023-09-15', 'gps_waypoint' => '145']);
        $data['rows'][1] = array_replace($data['rows'][1], ['gps_waypoint' => '007', 'pole_height_ft' => 36]);
        $data['header']['transformer_make'] = 'PEL';
        $data['header'] += ['division' => 'Division', 'sub_division' => 'Sub division', 'sub_division_code' => 'SD'];
        foreach ($data['rows'] as &$row) {
            $row += ['conductor_y' => '-', 'conductor_b' => '-', 'conductor_neutral' => 'W'];
        }
        unset($row);
        $data['solar'] = [];
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();

        return FieldSurvey::where('client_uuid', $data['client_uuid'])->firstOrFail();
    }

    private function source(?string $xml = null): MobileGpxSource
    {
        $xml ??= '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="31.7" lon="73.5"><name>007</name><time>'.today()->format('Y-m-d').'T05:00:00Z</time></wpt><wpt lat="31.8" lon="73.6"><name>008</name></wpt></gpx>';

        return app(MobileGpxWorkflow::class)->upload($this->admin, $this->team,
            UploadedFile::fake()->createWithContent('A_'.today()->format('dmy').'_06.gpx', $xml));
    }

    public function test_only_mobile_users_and_super_admin_can_login_and_old_role_tokens_are_denied(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->postJson('/api/v1/field/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Phone']);
            if (in_array($role, [UserRole::SuperAdmin, UserRole::MobileSurveyUser], true)) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
                $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))->getJson('/api/v1/field/bootstrap')->assertForbidden();
            }
        }
    }

    public function test_admin_creates_a_mobile_user_with_an_explicit_group_team(): void
    {
        $data = ['name' => 'Mobile Surveyor', 'email' => 'mobile@example.test', 'role' => 'mobile_survey_user',
            'password' => 'MobilePassword123', 'password_confirmation' => 'MobilePassword123'];
        $this->actingAs($this->admin)->post(route('admin.users.store'), $data)->assertSessionHasErrors('mobile_survey_team_id');
        $this->post(route('admin.users.store'), $data + ['mobile_survey_team_id' => $this->team->id])->assertRedirect()->assertSessionHasNoErrors();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue($user->hasRole('mobile_survey_user'));
        $this->assertTrue($user->surveyTeams()->whereKey($this->team->id)->exists());
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonPath('teams.0.group_identifier', '02');
        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertSee('Group 02');
    }

    public function test_historical_first_s_is_preserved_and_other_rows_require_assigned_group(): void
    {
        $data = $this->payload();
        $data['rows'][0]['group'] = '01';
        $data['rows'][1]['group'] = '03';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors('rows.1.group');
        $data['rows'][1]['group'] = '02';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $this->assertSame('01', FieldSurvey::first()->rows[0]['group']);
        $this->assertStringStartsWith('02_'.today()->format('dmy'), FieldSurvey::first()->survey_name);
    }

    public function test_one_original_gpx_links_to_multiple_transformers_without_copying_or_exporting_unused_points(): void
    {
        $first = $this->savedSurvey();
        $second = $this->savedSurvey('TR-026');
        $source = $this->source();
        $this->assertSame($source->id, $this->source()->id);
        $workflow = app(MobileGpxWorkflow::class);
        foreach ([$first, $second] as $survey) {
            $report = $workflow->preview($this->admin, $survey, [$source->id]);
            $this->assertSame([], $report['issues']);
            $this->assertSame([], $report['warnings']);
            $this->assertSame('007', $report['mapping'][1]['point']['name']);
            $link = $workflow->confirm($this->admin, $survey, [$source->id], 1, $report['preview_sha256'], null);
            $this->assertCount(1, $link->snapshot['waypoints']);
            $this->assertSame('01'.'150923'.'145', $link->snapshot['transformers'][0]['entry_rows'][0]['composite_identifier']);
        }
        $this->assertDatabaseCount('mobile_gpx_sources', 1);
        $this->assertDatabaseCount('mobile_gpx_links', 2);
        $this->actingAs($this->admin)->get(route('mobile-gpx.index'))->assertOk()->assertSee('TR-025')->assertSee('TR-026');
        $this->get(route('mobile-gpx.show', $first->client_uuid))->assertOk()->assertSee('GPX confirmed');
    }

    public function test_missing_and_ambiguous_points_block_confirmation_and_dates_with_warnings_need_explanation(): void
    {
        $survey = $this->savedSurvey();
        $workflow = app(MobileGpxWorkflow::class);
        $xml = '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="31" lon="73"><name>007</name></wpt><wpt lat="32" lon="74"><name>007</name></wpt></gpx>';
        $source = $this->source($xml);
        $report = $workflow->preview($this->admin, $survey, [$source->id]);
        $this->assertNotEmpty($report['issues']);
        $this->actingAs($this->admin)->post(route('mobile-gpx.confirm', $survey->client_uuid), ['source_ids' => [$source->id], 'survey_revision' => 1, 'preview_sha256' => $report['preview_sha256'], 'confirmed' => 1])->assertSessionHasErrors('gpx');
        $source = $this->source(str_replace('<wpt lat="32" lon="74"><name>007</name></wpt>', '', $xml));
        $report = $workflow->preview($this->admin, $survey, [$source->id]);
        $this->assertSame([], $report['issues']);
        $this->assertNotEmpty($report['warnings']);
        $this->post(route('mobile-gpx.confirm', $survey->client_uuid), ['source_ids' => [$source->id], 'survey_revision' => 1, 'preview_sha256' => $report['preview_sha256'], 'confirmed' => 1])->assertSessionHasErrors('confirmation_note');
    }

    public function test_creator_scope_and_stale_confirmations_are_enforced(): void
    {
        $survey = $this->savedSurvey();
        $source = $this->source();
        $this->seed(MdbWorkflowPermissionSeeder::class);
        $creator = User::factory()->create(['role' => UserRole::MdbGenerator]);
        $this->actingAs($creator)->get(route('mobile-gpx.show', $survey->client_uuid))->assertNotFound();
        $team = MdbTeam::create(['project_id' => $this->project->id, 'code' => 'MDB', 'name' => 'MDB', 'status' => 'active']);
        $team->members()->attach($creator->id);
        $this->get(route('mobile-gpx.show', $survey->client_uuid))->assertOk();
        $this->get('/field-surveys/'.$survey->client_uuid.'/edit')->assertForbidden();
        $workflow = app(MobileGpxWorkflow::class);
        $report = $workflow->preview($creator, $survey, [$source->id]);
        $link = $workflow->confirm($creator, $survey, [$source->id], 1, $report['preview_sha256'], null);
        $survey->update(['revision' => 2]);
        $this->assertNull($workflow->current($survey->fresh()));
        $this->post(route('mobile-gpx.export', $survey->client_uuid), ['survey_revision' => 1, 'link_id' => $link->id])->assertConflict();
        $this->post(route('mobile-gpx.confirm', $survey->client_uuid), ['source_ids' => [$source->id], 'survey_revision' => 1, 'preview_sha256' => $report['preview_sha256'], 'confirmed' => 1])->assertConflict();
    }

    public function test_confirmed_mobile_json_passes_existing_offline_mapping_and_contains_coordinates(): void
    {
        $survey = $this->savedSurvey();
        Configuration::create(['project_id' => $this->project->id, 'epsg' => 32643, 'revision' => 1,
            'settings' => ['simple_mdb_generation' => true], 'load_assumptions' => ['mode' => 'category_rates', 'rates' => []]]);
        app(SchemaTemplate::class)->ensure($this->admin);
        $source = $this->source();
        $workflow = app(MobileGpxWorkflow::class);
        $report = $workflow->preview($this->admin, $survey, [$source->id]);
        $link = $workflow->confirm($this->admin, $survey, [$source->id], 1, $report['preview_sha256'], null);
        $package = app(MobileSurveySnapshot::class)->export($link);
        $this->assertSame('mobile_survey', $package['source']['kind']);
        $this->assertEquals(31.7, $package['snapshot']['waypoints'][0]['latitude']);
        $inspection = app(SynergeeTransfer::class)->inspect($package);
        $this->assertSame([], $inspection['issues'], json_encode($inspection['issues']));
        $this->assertNotEmpty($inspection['payload']['tables']['Node']);
        $this->actingAs($this->admin)->post(route('mobile-gpx.export', $survey->client_uuid), ['survey_revision' => 1, 'link_id' => $link->id])->assertOk()->assertDownload();
        if (PHP_OS_FAMILY === 'Windows' && is_file(AccessPowerShell::defaultPath())) {
            config(['mdb_workflow.export.powershell' => AccessPowerShell::defaultPath()]);
            $output = app(SynergeeTransfer::class)->generate($package);
            $this->assertFileExists($output);
            $this->assertGreaterThan(4096, filesize($output));
            unlink($output);
        }
    }

    public function test_wrong_filename_groups_foreign_sources_and_changed_original_bytes_are_rejected(): void
    {
        $survey = $this->savedSurvey();
        $xml = '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="31.7" lon="73.5"><name>007</name></wpt></gpx>';
        $this->actingAs($this->admin)->post(route('mobile-gpx.upload'), ['survey_team_id' => $this->team->id,
            'gpx' => UploadedFile::fake()->createWithContent('B_'.today()->format('dmy').'_06.gpx', $xml)])->assertSessionHasErrors('gpx');
        $other = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'OTHER', 'name' => 'Other', 'group_code' => '03', 'gpx_group_code' => 'B', 'status' => 'active']);
        $source = app(MobileGpxWorkflow::class)->upload($this->admin, $other,
            UploadedFile::fake()->createWithContent('B_'.today()->format('dmy').'_06.gpx', $xml));
        $this->post(route('mobile-gpx.preview', $survey->client_uuid), ['source_ids' => [$source->id], 'survey_revision' => 1])->assertNotFound();
        $source = $this->source();
        Storage::disk('local')->put($source->path, $xml.' ');
        $this->post(route('mobile-gpx.preview', $survey->client_uuid), ['source_ids' => [$source->id], 'survey_revision' => 1])->assertSessionHasErrors('gpx');
    }

    public function test_complete_gpx_names_and_timestamp_warnings_preserve_original_mobile_identity(): void
    {
        $survey = $this->savedSurvey();
        $identity = '02'.today()->format('dmy').'007';
        $source = $this->source('<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><wpt lat="31.7" lon="73.5"><name>'.$identity.'</name></wpt></gpx>');
        $workflow = app(MobileGpxWorkflow::class);
        $report = $workflow->preview($this->admin, $survey, [$source->id]);
        $this->assertSame([], $report['issues']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertSame($identity, $report['mapping'][1]['complete_waypoint']);
        $link = $workflow->confirm($this->admin, $survey, [$source->id], 1, $report['preview_sha256'], 'Surveyor confirmed this GPS session; the device did not record timestamps.');
        $this->assertSame('007', $link->snapshot['mobile_survey']['data']['rows'][1]['gps_waypoint']);
        $this->assertSame($identity, $link->snapshot['waypoints'][0]['name']);
        $this->assertNotNull($link->confirmation_note);
        $this->actingAs($this->admin)->post(route('mobile-gpx.confirm', $survey->client_uuid), ['source_ids' => [$source->id],
            'survey_revision' => 1, 'preview_sha256' => str_repeat('0', 64), 'confirmed' => 1, 'confirmation_note' => 'Forged preview'])->assertConflict();
    }
}
