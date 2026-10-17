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
use App\Services\Mdb\EntryLookups;
use App\Support\SurveyTeamGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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
        $this->team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'ST', 'name' => 'Survey Team 01', 'status' => 'active']);
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
            'rows' => [['row_id' => $startId, 'se' => 'S', 'group' => '01', 'date' => today()->toDateString(), 'gps_waypoint' => '0008', 'phase' => '3', 'conductor_r' => 'A', 'equipment_type' => 'TR', 'pole_class' => 'PCO', 'pole_height_ft' => 36, 'consumers' => ['rs' => 5, 'lc' => 1], 'intersection' => true],
                ['row_id' => $endId, 'se' => 'E', 'group' => '01', 'date' => today()->toDateString(), 'gps_waypoint' => '0009', 'phase' => 'R', 'conductor_r' => 'A', 'equipment_type' => 'TR', 'pole_class' => 'PCO', 'pole_height_ft' => 0]],
            'solar' => [['row_id' => $startId, 'consumer_reference' => '0000123', 'installed_pv_kw' => 5.5, 'remarks' => 'Rooftop']], 'remarks' => 'Field visit',
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

    public function test_submitted_rows_require_every_field_and_allow_neutral_and_zero_values(): void
    {
        foreach (['se', 'group', 'date', 'gps_waypoint', 'equipment_type', 'pole_class', 'pole_height_ft'] as $field) {
            $data = $this->payload();
            unset($data['rows'][1][$field]);
            $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()
                ->assertJsonValidationErrors('rows.1.'.$field);
        }
        $data = $this->payload();
        $data['rows'][1]['conductor_r'] = '__none__';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()
            ->assertJsonValidationErrors('rows.1.conductor_neutral');
        $data['rows'][1]['conductor_neutral'] = 'A';
        $data['rows'][1]['pole_height_ft'] = 0;
        $data['rows'][1]['group'] = '01';
        $data['rows'][1]['gps_waypoint'] = '000';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $this->assertSame('01', FieldSurvey::firstOrFail()->rows[1]['group']);
        $this->assertSame('000', FieldSurvey::firstOrFail()->rows[1]['gps_waypoint']);
    }

    public function test_pair_ids_prevent_cross_pairing_duplicates_and_incomplete_submissions_but_keep_drafts(): void
    {
        $data = $this->payload();
        $pair = (string) Str::uuid();
        foreach ($data['rows'] as &$row) {
            $row['pair_id'] = $pair;
        }
        unset($row);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $this->assertSame($pair, FieldSurvey::firstOrFail()->rows[1]['pair_id']);

        $bad = $this->payload();
        $bad['rows'][0]['pair_id'] = (string) Str::uuid();
        $bad['rows'][1]['pair_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/field/surveys/sync', $bad)->assertUnprocessable()->assertJsonValidationErrors(['rows.0.pair', 'rows.1.pair']);
        $bad['rows'][1]['pair_id'] = $bad['rows'][0]['pair_id'];
        $bad['rows'][] = $bad['rows'][1];
        $this->postJson('/api/v1/field/surveys/sync', $bad)->assertUnprocessable()->assertJsonValidationErrors('rows.2.pair');
        $bad = $this->payload();
        array_pop($bad['rows']);
        $this->postJson('/api/v1/field/surveys/sync', $bad)->assertUnprocessable()->assertJsonValidationErrors('rows.0.pair');
        $bad['status'] = 'draft';
        $bad['transformer_code'] = 'OTHER-DRAFT';
        unset($bad['rows'][0]['group'], $bad['rows'][0]['equipment_type'], $bad['rows'][0]['pole_class'], $bad['rows'][0]['pole_height_ft']);
        $this->postJson('/api/v1/field/surveys/sync', $bad)->assertCreated();
        $this->assertDatabaseHas('field_surveys', ['client_uuid' => $bad['client_uuid'], 'status' => 'draft']);
        $legacy = $this->payload(); // Adjacent legacy pairs without pair IDs continue to submit.
        $legacy['transformer_code'] = 'OTHER-LEGACY';
        $this->postJson('/api/v1/field/surveys/sync', $legacy)->assertCreated();
    }

    public function test_mobile_entry_options_and_meter_only_pv_survive_sync_and_edit(): void
    {
        $options = $this->getJson('/api/v1/field/bootstrap')->assertOk()->json('feeders.0.entry_lookups');
        $this->assertSame(app(EntryLookups::class)->options($this->project->id)['equipment_types'], $options['equipment_types']);
        $data = $this->payload();
        $data['rows'][0] = array_replace($data['rows'][0], ['entry_version' => 2, 'group' => '01', 'gps_waypoint' => '017', 'equipment_type' => 'TR', 'phase' => 'WRONG']);
        $data['solar'] = [['row_id' => $data['rows'][0]['row_id'], 'consumer_reference' => '', 'service_load_kw' => 2.25, 'installed_pv_kw' => 3.5, 'meter_number' => '001-AB/09', 'remarks' => 'Meter only']];
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $survey = FieldSurvey::firstOrFail();
        $this->assertSame('001-AB/09', $survey->solar[0]['meter_number']);
        $this->assertSame(2.25, $survey->solar[0]['service_load_kw']);
        $this->assertSame('01', $survey->rows[0]['group']);
        $this->assertSame('017', $survey->rows[0]['gps_waypoint']);
        $this->assertSame('R', $survey->rows[0]['phase']);
        $this->assertTrue($survey->rows[0]['intersection']);
        $data['base_revision'] = 1;
        $data['rows'][0]['conductor_r'] = '';
        $data['rows'][0]['conductor_neutral'] = 'A';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk()->assertJsonPath('revision', 2);
        $this->assertSame('', $survey->fresh()->rows[0]['phase']);
        $this->assertSame('001-AB/09', $survey->fresh()->solar[0]['meter_number']);
        $data['base_revision'] = 2;
        $data['rows'][0]['group'] = '00a';
        $data['rows'][0]['gps_waypoint'] = '01-7';
        $data['rows'][0]['equipment_type'] = 'unlisted';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['rows.0.group', 'rows.0.gps_waypoint', 'rows.0.equipment_type']);
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

    public function test_team_group_is_a_default_and_edited_groups_survive_updates_and_team_renumbering(): void
    {
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonPath('teams.0.group_identifier', '01');
        $data = $this->payload();
        $data['rows'][0]['group'] = '12';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $this->team->update(['name' => 'Survey Team 12']);
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonPath('teams.0.group_identifier', '12');
        $data['base_revision'] = 1;
        $data['remarks'] = 'Saved groups retained';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk();
        $this->assertSame('12', FieldSurvey::firstOrFail()->rows[0]['group']);
        $data['base_revision'] = 2;
        $data['rows'][0]['group'] = '07';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk();
        $this->assertSame('07', FieldSurvey::firstOrFail()->rows[0]['group']);
        $this->team->update(['name' => 'Survey Team', 'code' => 'ST']);
        $new = $this->payload();
        $new['transformer_code'] = 'NEW';
        $this->postJson('/api/v1/field/surveys/sync', $new)->assertCreated();
        $this->team->update(['name' => 'Survey Team 01', 'code' => 'ST12']);
        $this->assertNull(SurveyTeamGroup::identifier($this->team->fresh()));
        $this->team->update(['name' => 'Survey Team 101', 'code' => 'ST01']);
        $this->assertSame('01', SurveyTeamGroup::identifier($this->team->fresh()));
    }

    public function test_pv_and_photo_links_survive_reordering_and_reject_foreign_or_missing_rows(): void
    {
        $data = $this->payload('draft');
        $data['solar'][0]['pv_id'] = (string) Str::uuid();
        $start = $data['rows'][0]['row_id'];
        $end = $data['rows'][1]['row_id'];
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $url = '/api/v1/field/surveys/'.$data['client_uuid'].'/attachments';
        $photo = ['client_uuid' => (string) Str::uuid(), 'row_id' => $end, 'kind' => 'sketch'];
        $this->post($url, $photo + ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('row_id', $end);
        $this->post($url, array_replace($photo, ['row_id' => $start, 'file' => $this->pdf()]), ['Accept' => 'application/json'])->assertConflict();
        $this->post($url, ['client_uuid' => (string) Str::uuid(), 'row_id' => (string) Str::uuid(), 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('row_id');
        $this->post($url, ['client_uuid' => (string) Str::uuid(), 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('row_id');
        $data['base_revision'] = 1;
        $data['rows'] = array_reverse($data['rows']);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk();
        $this->assertSame($start, FieldSurvey::firstOrFail()->solar[0]['row_id']);
        $this->assertSame($end, FieldSurveyAttachment::firstOrFail()->row_id);
        $data['base_revision'] = 2;
        $bad = $data;
        $bad['solar'][0]['row_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/field/surveys/sync', $bad)->assertUnprocessable()->assertJsonValidationErrors('solar.0.row_id');
        $bad = $data;
        unset($bad['solar'][0]['row_id']);
        $this->postJson('/api/v1/field/surveys/sync', $bad)->assertUnprocessable()->assertJsonValidationErrors('solar.0.row_id');
        array_shift($data['rows']);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors('rows');
    }

    public function test_complete_mobile_contract_submits_and_preserves_all_fields_row_media_and_edited_groups(): void
    {
        $data = json_decode(file_get_contents(base_path('tests/Fixtures/FieldSurvey/mobile-submission.json')), true, 512, JSON_THROW_ON_ERROR);
        $data['survey_team_id'] = $this->team->id;
        $data['feeder_id'] = $this->feeder->id;
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated()->assertJsonPath('status', 'submitted');
        $survey = FieldSurvey::firstOrFail();
        $this->assertSame($data['header'], $survey->header);
        $this->assertSame($data['rows'], $survey->rows);
        $this->assertSame($data['solar'], $survey->solar);
        $this->assertSame($data['remarks'], $survey->remarks);
        $this->assertSame($data['transformer_code'], $survey->transformer_code);
        $this->assertNotNull($survey->submitted_at);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk()->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('field_surveys', 1);

        $start = $data['rows'][0]['row_id'];
        $end = $data['rows'][1]['row_id'];
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7j8AAAAASUVORK5CYII=');
        $this->post('/api/v1/field/surveys/'.$data['client_uuid'].'/attachments', [
            'client_uuid' => (string) Str::uuid(), 'row_id' => $end, 'kind' => 'photo',
            'file' => UploadedFile::fake()->createWithContent('row-photo.png', $bytes),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('row_id', $end);
        $attachment = FieldSurveyAttachment::firstOrFail();
        $this->assertSame(hash('sha256', $bytes), $attachment->sha256);
        Storage::disk('local')->assertExists($attachment->path);

        $this->team->update(['name' => 'Survey Team 88']);
        $data['base_revision'] = 1;
        $data['rows'][0]['group'] = '08';
        $data['rows'] = array_reverse($data['rows']);
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk()->assertJsonPath('revision', 2);
        $survey->refresh();
        $this->assertSame('09', $survey->rows[0]['group']);
        $this->assertSame('08', $survey->rows[1]['group']);
        $this->assertSame($start, $survey->solar[0]['row_id']);
        $this->assertSame($end, $attachment->fresh()->row_id);
        $this->assertSame($data['rows'], $survey->rows);
        $this->assertSame($data['solar'], $survey->solar);
        $this->assertSame($bytes, Storage::disk('local')->get($attachment->path));
    }

    public function test_group_identifier_requires_exactly_two_digits_for_new_and_existing_rows(): void
    {
        foreach (['0', '001', '0a', '00.1', 1, null] as $group) {
            $data = $this->payload();
            $data['rows'][0]['group'] = $group;
            $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors('rows.0.group');
        }
        $this->assertDatabaseCount('field_surveys', 0);
        $data = $this->payload();
        $data['rows'][0]['group'] = '00';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $data['base_revision'] = 1;
        $data['rows'][0]['group'] = '007';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors('rows.0.group');
        $this->assertSame('00', FieldSurvey::firstOrFail()->rows[0]['group']);
    }

    public function test_legacy_unlinked_media_and_original_groups_are_preserved_when_row_ids_are_added(): void
    {
        $data = $this->payload('draft');
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $legacy = $data;
        foreach ($legacy['rows'] as &$row) {
            unset($row['row_id']);
            $row['group'] = '01';
        }
        unset($row);
        unset($legacy['solar'][0]['row_id']);
        $survey = FieldSurvey::firstOrFail();
        $survey->update(['rows' => $legacy['rows'], 'solar' => $legacy['solar']]);
        $attachment = $survey->attachments()->create(['client_uuid' => (string) Str::uuid(), 'kind' => 'photo', 'path' => 'legacy.jpg', 'mime_type' => 'image/jpeg', 'byte_length' => 10, 'sha256' => str_repeat('a', 64)]);
        $legacy['base_revision'] = 1;
        foreach ($legacy['rows'] as $i => &$row) {
            $row['row_id'] = (string) Str::uuid();
            $row['legacy_row_index'] = $i;
        }
        unset($row);
        $legacy['rows'] = array_reverse($legacy['rows']);
        $this->postJson('/api/v1/field/surveys/sync', $legacy)->assertOk();
        $this->assertSame('01', $survey->fresh()->rows[0]['group']);
        $this->assertArrayNotHasKey('row_id', $survey->fresh()->solar[0]);
        $this->assertNull($attachment->fresh()->row_id);
    }

    public function test_saved_transformers_are_hidden_and_duplicate_codes_or_references_rejected_across_collectors(): void
    {
        $transformer = Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => 'B2308', 'capacity_kva' => 100, 'latitude' => 33.1, 'longitude' => 73.1]);
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonCount(1, 'transformers');
        $data = $this->payload();
        $data['transformer_id'] = $transformer->id;
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonCount(0, 'transformers');
        $other = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->team->members()->attach($other->id);
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other));
        $duplicate = $this->payload();
        $duplicate['transformer_code'] = ' b 2308 ';
        $this->postJson('/api/v1/field/surveys/sync', $duplicate)->assertUnprocessable()->assertJsonValidationErrors('transformer_code');
        $duplicate['transformer_code'] = 'Different label';
        $duplicate['transformer_id'] = $transformer->id;
        $this->postJson('/api/v1/field/surveys/sync', $duplicate)->assertUnprocessable()->assertJsonValidationErrors('transformer_code');
        $this->withHeader('Authorization', 'Bearer '.$this->token);
        $data['base_revision'] = 1;
        $data['remarks'] = 'Edit remains possible';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk();
        $this->assertDatabaseCount('field_surveys', 1);
    }

    public function test_additive_migration_preserves_legacy_records_and_duplicates_remain_editable(): void
    {
        $data = $this->payload('draft');
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $original = FieldSurvey::firstOrFail();
        $original->update(['transformer_code' => ' b 2308 ']);
        $duplicate = $original->replicate();
        $duplicate->client_uuid = (string) Str::uuid();
        $duplicate->save();
        $migration = require database_path('migrations/2026_10_17_000200_link_field_media_and_index_transformer_identity.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('field_surveys', 2);
        $this->assertSame('B2308', $original->fresh()->transformer_key);
        $this->assertSame($data['solar'], $original->fresh()->solar);
        $this->assertSame($data['rows'][0]['row_id'], $original->fresh()->rows[0]['row_id']);
        $data['base_revision'] = 1;
        $data['remarks'] = 'Existing duplicate still editable';
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertOk();
        $this->assertSame('Existing duplicate still editable', $original->fresh()->remarks);
        $data['client_uuid'] = (string) Str::uuid();
        $data['base_revision'] = 0;
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertUnprocessable()->assertJsonValidationErrors('transformer_code');
    }

    public function test_private_attachments_are_idempotent_and_different_content_conflicts(): void
    {
        config(['drive_storage.enabled' => true]);
        Queue::fake();
        $data = $this->payload();
        $this->postJson('/api/v1/field/surveys/sync', $data)->assertCreated();
        $uuid = (string) Str::uuid();
        $url = '/api/v1/field/surveys/'.$data['client_uuid'].'/attachments';
        $this->post($url, ['client_uuid' => $uuid, 'row_id' => $data['rows'][0]['row_id'], 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $this->post($url, ['client_uuid' => $uuid, 'row_id' => $data['rows'][0]['row_id'], 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk();
        $this->post($url, ['client_uuid' => $uuid, 'row_id' => $data['rows'][0]['row_id'], 'kind' => 'sketch', 'file' => $this->pdf('Changed')], ['Accept' => 'application/json'])->assertConflict();
        $this->assertDatabaseCount('field_survey_attachments', 1);
        $this->assertDatabaseCount('drive_stored_files', 1);
        $this->assertSame($this->feeder->id, FieldSurveyAttachment::first()->driveStoredFile->context['feeder_id']);
        Storage::disk('local')->assertExists(FieldSurveyAttachment::first()->path);
        $this->get('/api/v1/field/attachments/'.$uuid)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        FeederAssignment::query()->update(['status' => 'inactive']);
        $this->getJson('/api/v1/field/attachments/'.$uuid)->assertForbidden();
        $this->post($url, ['client_uuid' => $uuid, 'row_id' => $data['rows'][0]['row_id'], 'kind' => 'sketch', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
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
