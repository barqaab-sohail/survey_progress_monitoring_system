<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\FieldSurvey;
use App\Models\FieldSurveyAttachment;
use App\Models\GridStation;
use App\Models\MobileDeviceToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyDailyEntry;
use App\Models\SurveyTeam;
use App\Models\Transformer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldSurveyApiTest extends TestCase
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

    public function test_login_uses_hashed_expiring_device_tokens_and_logout_revokes_only_current_device(): void
    {
        $login = $this->postJson('/api/v1/field/login', ['email' => $this->leader->email, 'password' => 'password', 'device_name' => 'Android phone'])->assertOk();
        $plain = $login->json('token');
        $this->assertSame(128, strlen($plain));
        $this->assertDatabaseHas('mobile_device_tokens', ['user_id' => $this->leader->id, 'token_hash' => hash('sha256', $plain)]);
        $this->assertDatabaseMissing('mobile_device_tokens', ['token_hash' => $plain]);
        $this->assertTrue(MobileDeviceToken::where('token_hash', hash('sha256', $plain))->first()->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/v1/field/logout')->assertNoContent();
        $this->getJson('/api/v1/field/bootstrap')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/v1/field/bootstrap')->assertOk();
    }

    public function test_tracking_device_activity_preserves_the_thirty_day_expiry(): void
    {
        $login = $this->postJson('/api/v1/field/login', ['email' => $this->leader->email, 'password' => 'password', 'device_name' => 'Expiry test phone'])->assertOk();
        $token = MobileDeviceToken::where('token_hash', hash('sha256', $login->json('token')))->firstOrFail();
        $expiry = $token->expires_at->toDateTimeString();
        $this->withHeader('Authorization', 'Bearer '.$login->json('token'))->getJson('/api/v1/field/bootstrap')->assertOk();
        $this->assertSame($expiry, $token->fresh()->expires_at->toDateTimeString());
        $this->assertNotNull($token->fresh()->last_used_at);
        $this->travel(6)->minutes();
        $this->getJson('/api/v1/field/bootstrap')->assertOk();
        $this->postJson('/api/v1/field/surveys/sync', $this->payload())->assertCreated();
        $this->assertSame($expiry, $token->fresh()->expires_at->toDateTimeString());
        $this->assertTrue($token->fresh()->expires_at->isFuture());
    }

    public function test_login_is_limited_by_email_and_ip_and_rejects_other_roles(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/field/login', ['email' => $this->leader->email, 'password' => 'wrong', 'device_name' => 'Phone'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/field/login', ['email' => $this->leader->email, 'password' => 'password', 'device_name' => 'Phone'])->assertStatus(429);
        $viewer = User::factory()->create(['role' => UserRole::ManagementViewer]);
        $this->postJson('/api/v1/field/login', ['email' => $viewer->email, 'password' => 'password', 'device_name' => 'Phone'])->assertForbidden();
    }

    public function test_expired_tokens_and_inactive_users_or_organizations_are_rejected(): void
    {
        MobileDeviceToken::where('token_hash', hash('sha256', $this->token))->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/field/bootstrap')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->leader));
        $this->leader->update(['status' => 'inactive']);
        $this->getJson('/api/v1/field/bootstrap')->assertForbidden();
        $this->leader->update(['status' => 'active']);
        $this->organization->update(['status' => 'inactive']);
        $this->getJson('/api/v1/field/bootstrap')->assertForbidden();
    }

    public function test_bootstrap_excludes_unassigned_feeders_and_inactive_projects_or_memberships(): void
    {
        $other = $this->feeder->replicate();
        $other->feeder_code = 'OTHER';
        $other->save();
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonCount(1, 'feeders')->assertJsonPath('feeders.0.feeder_code', '0012')->assertJsonPath('feeders.0.team_ids.0', $this->team->id)->assertJsonPath('feeders.0.sub_division_code', 'SD');
        $this->team->members()->detach($this->leader->id);
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonCount(0, 'teams')->assertJsonCount(0, 'feeders');
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin));
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonCount(1, 'feeders');
        $this->project->update(['status' => 'inactive']);
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonCount(0, 'teams')->assertJsonCount(0, 'feeders');
    }

    public function test_full_sheet_sync_preserves_paper_fields_and_leading_zeros_without_creating_progress(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated()->assertJsonPath('revision', 1);
        $survey = FieldSurvey::firstOrFail();
        $this->assertSame('0008', $survey->rows[0]['gps_waypoint']);
        $this->assertSame('01', $survey->rows[0]['group']);
        $this->assertTrue($survey->rows[0]['intersection']);
        $this->assertSame('0000123', $survey->solar[0]['consumer_reference']);
        $this->assertSame($data['header'], $survey->header);
        $this->assertSame(0, SurveyDailyEntry::count());
        $this->assertSame(0, Transformer::count());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_same_uuid_retry_is_idempotent_and_edits_require_current_revision(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk()->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('field_surveys', 1);
        $data['remarks'] = 'Corrected';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertConflict();
        $data['base_revision'] = 1;
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk()->assertJsonPath('revision', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $data['remarks'] = 'Stale edit';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertConflict();
        $this->assertSame('Corrected', FieldSurvey::first()->remarks);
    }

    public function test_incomplete_drafts_are_allowed_and_submitted_requirements_are_enforced(): void
    {
        $draft = $this->payload('draft');
        $draft['transformer_code'] = '';
        $draft['header'] = [];
        $draft['rows'] = [];
        $draft['solar'] = [];
        $this->postJson('/api/v1/field/surveys/sync', $draft)->assertCreated();
        $draft['status'] = 'submitted';
        $draft['base_revision'] = 1;
        $this->postJson('/api/v1/field/surveys/sync', $draft)->assertUnprocessable()->assertJsonValidationErrors(['transformer_code', 'header.capacity_kva', 'header.inspectors', 'rows']);
        $data = $this->payload();
        unset($data['rows'][0]['se'], $data['rows'][0]['date'], $data['rows'][0]['gps_waypoint']);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors(['rows.0.se', 'rows.0.date', 'rows.0.gps_waypoint']);
    }

    public function test_numeric_ranges_unknown_keys_and_row_limits_are_validated(): void
    {
        $data = $this->payload();
        $data['rows'][0]['latitude'] = 91;
        $data['rows'][0]['longitude'] = -181;
        $data['rows'][0]['consumers']['rs'] = -1;
        $data['solar'][0]['installed_pv_kw'] = -5;
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors(['rows.0.latitude', 'rows.0.longitude', 'rows.0.consumers.rs', 'solar.0.installed_pv_kw']);
        $data = $this->payload();
        $data['header']['unexpected'] = 'Must not be stored';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors('header');
        $data = $this->payload();
        $data['rows'] = array_fill(0, 501, $data['rows'][0]);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors('rows');
    }

    public function test_sync_rechecks_assignment_membership_and_project_before_accepting_retries(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        FeederAssignment::query()->update(['status' => 'inactive']);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertForbidden();
        FeederAssignment::query()->update(['status' => 'active', 'end_date' => today()->subDay()]);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertForbidden();
        FeederAssignment::query()->update(['end_date' => null]);
        $this->team->members()->detach($this->leader->id);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertForbidden();
        $this->team->members()->attach($this->leader->id);
        $this->project->update(['status' => 'inactive']);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertForbidden();
    }

    public function test_another_collector_cannot_reuse_uuid_even_on_same_team(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $other = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->team->members()->attach($other->id);
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other));
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertConflict();
        $this->assertSame($this->leader->id, FieldSurvey::first()->collected_by);
    }

    public function test_gis_reference_is_linked_without_mutation_and_surveys_survive_reference_deletion(): void
    {
        $transformer = Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => 'REF', 'capacity_kva' => 100, 'latitude' => 33.1, 'longitude' => 73.1, 'equipment_make' => 'Original']);
        $data = $this->payload();
        $data['transformer_id'] = $transformer->id;
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $this->assertSame('Original', $transformer->fresh()->equipment_make);
        $this->assertSame('100.00', $transformer->fresh()->capacity_kva);
        $transformer->delete();
        $survey = FieldSurvey::first();
        $this->assertNull($survey->transformer_id);
        $this->assertSame('REF', $survey->reference_snapshot['transformer']['transformer_code']);
        $this->assertSame(50, $survey->header['capacity_kva']);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk()->assertJsonPath('revision', 1);
    }

    private function pdf(string $text = 'Sketch'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('sketch.pdf', "%PDF-1.7\n".$text."\n%%EOF");
    }

    public function test_private_attachments_are_idempotent_and_different_content_conflicts(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $uuid = (string) Str::uuid();
        $url = '/api/v1/field/surveys/'.$data['client_uuid'].'/attachments';
        $this->post($url, ['client_uuid' => $uuid, 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $this->post($url, ['client_uuid' => $uuid, 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk();
        $this->post($url, ['client_uuid' => $uuid, 'kind' => 'sketch', 'file' => $this->pdf('Changed')], ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('field_survey_attachments', 1);
        Storage::disk('local')->assertExists(FieldSurveyAttachment::first()->path);
        $this->get('/api/v1/field/attachments/'.$uuid)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        FeederAssignment::query()->update(['status' => 'inactive']);
        $this->getJson('/api/v1/field/attachments/'.$uuid)->assertForbidden();
        $this->post($url, ['client_uuid' => $uuid, 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_upload_type_size_and_owner_are_enforced(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $url = '/api/v1/field/surveys/'.$data['client_uuid'].'/attachments';
        $this->post($url, ['client_uuid' => (string) Str::uuid(), 'kind' => 'photo', 'file' => UploadedFile::fake()->createWithContent('danger.php', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['client_uuid' => (string) Str::uuid(), 'kind' => 'photo', 'file' => UploadedFile::fake()->create('large.jpg', 10241, 'image/jpeg')], ['Accept' => 'application/json'])->assertUnprocessable();
        $other = User::factory()->create(['role' => UserRole::SurveyTeamLeader]);
        $this->team->members()->attach($other->id);
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other));
        $this->post($url, ['client_uuid' => (string) Str::uuid(), 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseCount('field_survey_attachments', 0);
    }

    public function test_web_review_is_read_only_and_scoped_to_management_or_assigned_owner(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $viewer = User::factory()->create(['role' => UserRole::ManagementViewer]);
        $this->actingAs($viewer)->get('/field-surveys')->assertOk()->assertSee('B2308')->assertSee('Mobile Field Surveys');
        $this->get('/field-surveys/'.$data['client_uuid'])->assertOk()->assertSee('0008')->assertSee('0000123')->assertSee('Intersection');
        $other = User::factory()->create(['role' => UserRole::SurveyTeamLeader]);
        $this->actingAs($other)->get('/field-surveys/'.$data['client_uuid'])->assertNotFound();
        $this->actingAs($this->leader)->get('/field-surveys/'.$data['client_uuid'])->assertOk();
        FeederAssignment::query()->update(['status' => 'inactive']);
        $this->get('/field-surveys/'.$data['client_uuid'])->assertNotFound();
        $this->actingAs($viewer)->get('/field-surveys/'.$data['client_uuid'])->assertOk();
        $this->post('/field-surveys', [])->assertStatus(405);
    }
}
