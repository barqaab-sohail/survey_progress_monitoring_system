<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\FieldSurveyTest;
use App\Models\FieldSurveyTestAttachment;
use App\Models\GridStation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldSurveyWebTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;

    private User $admin;

    private Organization $organization;

    private Project $project;

    private SurveyTeam $team;

    private Feeder $feeder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->organization = Organization::create(['name' => 'Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $this->leader = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::SuperAdmin]);
        $this->project = Project::create(['code' => 'FIELD', 'name' => 'Field project', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $this->project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $this->project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Sub division']);
        $grid = GridStation::create(['project_id' => $this->project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G', 'name' => 'Substation']);
        $this->feeder = Feeder::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => '0012', 'feeder_name' => 'Assigned feeder', 'total_transformers' => 0, 'baseline_pending' => true, 'status' => 'active']);
        $this->team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'ST', 'name' => 'Survey Team', 'status' => 'active']);
        $this->team->members()->attach($this->leader->id, ['is_leader' => true]);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->team->id, 'assigned_by' => $this->admin->id, 'start_date' => today()->subDay(), 'status' => 'active']);
    }

    private function payload(string $status = 'submitted'): array
    {
        return [
            'client_uuid' => (string) Str::uuid(), 'base_revision' => 0,
            'survey_team_id' => $this->team->id, 'feeder_id' => $this->feeder->id,
            'transformer_id' => null, 'transformer_code' => 'B2308',
            'survey_date' => today()->toDateString(), 'status' => $status,
            'header' => ['substation' => 'Substation', 'capacity_kva' => 50, 'inspectors' => 'Field inspector', 'location' => 'Street 1', 'mounting' => 'D.Pole', 'duty' => 'General Duty'],
            'rows' => [['se' => 'S', 'group' => '01', 'date' => today()->toDateString(), 'gps_waypoint' => '0008', 'phase' => '3', 'conductor_r' => 'A', 'pole_class' => 'PCO', 'pole_height_ft' => 36, 'consumers' => ['rs' => 5, 'lc' => 1], 'intersection' => true]],
            'solar' => [['consumer_reference' => '0000123', 'installed_pv_kw' => 5.5, 'remarks' => 'Rooftop']], 'remarks' => 'Field visit',
        ];
    }

    public function test_phase_is_derived_from_phase_conductors_only(): void
    {
        $this->actingAs($this->admin);
        foreach (['' => [], 'R' => ['r'], 'Y' => ['y'], 'B' => ['b'], 'RY' => ['r', 'y'], 'RB' => ['r', 'b'], 'YB' => ['y', 'b'], 'RYB' => ['r', 'y', 'b']] as $expected => $present) {
            $data = $this->payload();
            foreach (['r', 'y', 'b'] as $key) {
                $data['rows'][0]['conductor_'.$key] = in_array($key, $present, true) ? ' GN ' : '+';
            }
            $data['rows'][0]['conductor_neutral'] = 'A';
            $data['rows'][0]['phase'] = 'wrong manual value';
            $this->postJson(route('field-survey-test.sync'), $data)->assertOk();
            $this->assertSame($expected, FieldSurveyTest::where('client_uuid', $data['client_uuid'])->firstOrFail()->payload['rows'][0]['phase']);
        }
    }

    public function test_intersection_is_a_separate_boolean_and_does_not_change_consumer_counts(): void
    {
        $this->actingAs($this->admin);
        foreach ([true, false, 'Int'] as $value) {
            $data = $this->payload();
            $data['rows'][0]['intersection'] = $value;
            $this->postJson(route('field-survey-test.sync'), $data)->assertOk();
            $row = FieldSurveyTest::where('client_uuid', $data['client_uuid'])->firstOrFail()->payload['rows'][0];
            $this->assertSame($value === 'Int' ? true : $value, $row['intersection']);
            $this->assertSame(['rs' => 5, 'lc' => 1], $row['consumers']);
        }
        $data['client_uuid'] = (string) Str::uuid();
        $data['rows'][0]['intersection'] = 'not a checkbox value';
        $this->postJson(route('field-survey-test.sync'), $data)->assertUnprocessable()->assertJsonValidationErrors('rows.0.intersection');
    }

    public function test_all_test_routes_and_navigation_are_super_admin_only(): void
    {
        $this->get(route('field-survey-test.index'))->assertRedirect(route('login'));
        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::SuperAdmin) {
                continue;
            }
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->get(route('field-survey-test.index'))->assertForbidden();
            $this->getJson(route('field-survey-test.bootstrap'))->assertForbidden();
            $this->getJson(route('field-survey-test.records'))->assertForbidden();
            $this->postJson(route('field-survey-test.sync'), $this->payload())->assertForbidden();
            $this->postJson(route('field-survey-test.attach', (string) Str::uuid()))->assertForbidden();
            $this->get(route('field-survey-test.download', (string) Str::uuid()))->assertForbidden();
            $this->get(route('profile.edit'))->assertOk()->assertDontSee('Android Survey Web Test');
        }
        $this->actingAs($this->admin)->get(route('field-survey-test.index'))->assertOk()->assertSee('Android Survey Web Test')->assertSee('Simulate offline');
        $this->getJson(route('field-survey-test.bootstrap'))->assertOk()->assertJsonPath('feeders.0.feeder_code', '0012');
    }

    public function test_full_android_payload_is_preserved_in_isolated_tables_and_restored(): void
    {
        $data = $this->payload();
        $this->actingAs($this->admin)->postJson(route('field-survey-test.sync'), $data)->assertOk()->assertJsonPath('revision', 1);
        $record = FieldSurveyTest::firstOrFail();
        $data['rows'][0]['phase'] = 'R';
        $this->assertEquals($data, $record->payload);
        $this->getJson(route('field-survey-test.records'))->assertOk()->assertJsonPath('0.data.base_revision', 1)->assertJsonPath('0.data.rows.0.gps_waypoint', '0008')->assertJsonPath('0.data.solar.0.consumer_reference', '0000123');
        foreach (['field_surveys', 'field_survey_attachments', 'survey_daily_entries', 'mdb_daily_entries', 'transformers'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, $this->feeder->fresh()->total_transformers);
    }

    public function test_sync_is_idempotent_and_stale_revisions_cannot_overwrite(): void
    {
        $data = $this->payload();
        $this->actingAs($this->admin)->postJson(route('field-survey-test.sync'), $data)->assertOk()->assertJsonPath('revision', 1);
        $this->postJson(route('field-survey-test.sync'), $data)->assertOk()->assertJsonPath('revision', 1);
        $data['remarks'] = 'Changed';
        $this->postJson(route('field-survey-test.sync'), $data)->assertConflict();
        $data['base_revision'] = 1;
        $this->postJson(route('field-survey-test.sync'), $data)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson(route('field-survey-test.sync'), $data)->assertOk()->assertJsonPath('revision', 2);
        $data['remarks'] = 'Stale';
        $this->postJson(route('field-survey-test.sync'), $data)->assertConflict();
        $this->assertSame('Changed', FieldSurveyTest::first()->payload['remarks']);
        $this->assertDatabaseCount('field_survey_tests', 1);
    }

    public function test_validation_matches_android_and_assignment_changes_are_enforced(): void
    {
        $this->actingAs($this->admin);
        $draft = $this->payload('draft');
        $draft['transformer_code'] = '';
        $draft['header'] = [];
        $draft['rows'] = [];
        $draft['solar'] = [];
        $this->postJson(route('field-survey-test.sync'), $draft)->assertOk();
        $draft['status'] = 'submitted';
        $this->postJson(route('field-survey-test.sync'), $draft)->assertUnprocessable()->assertJsonValidationErrors(['transformer_code', 'header.capacity_kva', 'header.inspectors', 'rows']);
        $bad = $this->payload();
        $bad['rows'][0]['consumers']['rs'] = 1.5;
        $bad['rows'][0]['latitude'] = 95;
        $bad['survey_date'] = today()->addDay()->toDateString();
        $this->postJson(route('field-survey-test.sync'), $bad)->assertUnprocessable()->assertJsonValidationErrors(['rows.0.consumers.rs', 'rows.0.latitude', 'survey_date']);
        $this->team->update(['status' => 'inactive']);
        $this->postJson(route('field-survey-test.sync'), $this->payload())->assertForbidden();
    }

    public function test_attachments_are_private_idempotent_and_separate_from_android(): void
    {
        $data = $this->payload();
        $this->actingAs($this->admin)->postJson(route('field-survey-test.sync'), $data)->assertOk();
        $uuid = (string) Str::uuid();
        $upload = fn () => UploadedFile::fake()->createWithContent('sketch.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $this->post(route('field-survey-test.attach', $data['client_uuid']), ['client_uuid' => $uuid, 'kind' => 'sketch', 'file' => $upload()], ['Accept' => 'application/json'])->assertOk();
        $this->post(route('field-survey-test.attach', $data['client_uuid']), ['client_uuid' => $uuid, 'kind' => 'sketch', 'file' => $upload()], ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('field_survey_test_attachments', 1);
        $this->assertDatabaseCount('field_survey_attachments', 0);
        $file = FieldSurveyTestAttachment::firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->get(route('field-survey-test.download', $uuid))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post(route('field-survey-test.attach', $data['client_uuid']), ['client_uuid' => $uuid, 'kind' => 'photo', 'file' => $upload()], ['Accept' => 'application/json'])->assertConflict();
        $this->post(route('field-survey-test.attach', $data['client_uuid']), ['client_uuid' => (string) Str::uuid(), 'kind' => 'photo', 'file' => UploadedFile::fake()->createWithContent('bad.txt', 'bad')], ['Accept' => 'application/json'])->assertUnprocessable();
        $other = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($other)->get(route('field-survey-test.download', $uuid))->assertNotFound();
        $this->getJson(route('field-survey-test.records'))->assertOk()->assertExactJson([]);
        $this->postJson(route('field-survey-test.sync'), $data)->assertConflict();
    }
}
