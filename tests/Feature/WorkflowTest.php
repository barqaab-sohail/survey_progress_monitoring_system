<?php

namespace Tests\Feature;

use App\Enums\SurveyItemStatus;
use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\MdbTeam;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\MdbCreationService;
use App\Services\MdbProcessingService;
use App\Services\SurveyProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $internal;

    private Organization $external;

    private Feeder $feeder;

    private SurveyTeam $surveyTeam;

    private MdbTeam $mdbTeam;

    private User $admin;

    private User $surveyUser;

    private User $mdbUser;

    private User $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->internal = Organization::create(['name' => 'BARQAAB', 'type' => 'internal', 'status' => 'active']);
        $this->external = Organization::create(['name' => 'Processor A', 'type' => 'third_party', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::SuperAdmin]);
        $this->surveyUser = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->mdbUser = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::MdbTeamUser]);
        $this->processor = User::factory()->create(['organization_id' => $this->external->id, 'role' => UserRole::MdbProcessingUser]);
        $project = Project::create(['code' => 'P1', 'name' => 'Project', 'timezone' => 'Asia/Karachi', 'processing_required' => true, 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C1', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D1', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD1', 'name' => 'Sub Division']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G1', 'name' => 'Grid']);
        $this->feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F-01', 'feeder_name' => 'Feeder 01', 'total_transformers' => 100, 'processing_required' => true, 'status' => 'active']);
        $this->surveyTeam = SurveyTeam::create(['project_id' => $project->id, 'code' => 'ST1', 'name' => 'Survey Team', 'status' => 'active']);
        $this->surveyTeam->members()->attach($this->surveyUser->id, ['is_leader' => true]);
        $this->mdbTeam = MdbTeam::create(['project_id' => $project->id, 'code' => 'MT1', 'name' => 'MDB Team', 'status' => 'active']);
        $this->mdbTeam->members()->attach($this->mdbUser->id);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->surveyTeam->id, 'assigned_by' => $this->admin->id, 'start_date' => today(), 'status' => 'active']);
    }

    public function test_authentication_and_role_authorization_are_enforced(): void
    {
        $this->get('/')->assertRedirect('/login');
        $viewer = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::ManagementViewer]);
        $this->actingAs($viewer)->get('/survey/create')->assertForbidden();
        $this->actingAs($this->surveyUser)->get('/survey/create')->assertOk();
    }

    public function test_survey_form_explains_missing_feeder_assignments(): void
    {
        FeederAssignment::query()->delete();

        $this->actingAs($this->surveyUser)->get('/survey/create')
            ->assertOk()
            ->assertSee('No feeders assigned')
            ->assertSee('Excel import adds feeder master data.')
            ->assertDontSee('Submit for verification');
    }

    public function test_assigned_imported_feeders_appear_with_baseline_guidance(): void
    {
        $this->feeder->update(['baseline_pending' => true, 'total_transformers' => 0]);

        $this->actingAs($this->surveyUser)->get('/survey/create')
            ->assertOk()
            ->assertSee('F-01')
            ->assertSee('baseline pending')
            ->assertSee('need verified transformer totals');

        $this->actingAs($this->surveyUser)->post('/survey', [
            'entry_date' => today()->toDateString(),
            'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 1, 'drive_url' => 'https://drive.google.com/drive/folders/survey-evidence']],
        ])->assertSessionHasErrors('items');
        $this->assertDatabaseCount('survey_daily_entries', 0);
    }

    public function test_survey_validation_preserves_all_entered_rows_and_fields(): void
    {
        $data = [
            'entry_date' => today()->subDay()->toDateString(),
            'remarks' => 'Overall notes & details',
            'items' => [
                ['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 12, 'drive_url' => 'https://example.com/first', 'remarks' => 'First row notes'],
                ['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 23, 'drive_url' => 'https://example.com/second', 'remarks' => 'Second row notes'],
            ],
        ];
        $this->actingAs($this->surveyUser)->from('/survey/create')->post('/survey', $data)
            ->assertRedirect('/survey/create')
            ->assertSessionHasErrors('items.0.feeder_id')
            ->assertSessionHasInput('items', $data['items']);

        $response = $this->get('/survey/create')->assertOk();
        $response->assertSee('value="'.$data['entry_date'].'"', false)
            ->assertSee('Overall notes &amp; details', false)
            ->assertSee('value="12"', false)->assertSee('value="23"', false)
            ->assertSee('https://example.com/first')->assertSee('https://example.com/second')
            ->assertSee('First row notes')->assertSee('Second row notes')
            ->assertSee('name="items[1][feeder_id]"', false);
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $selectedFeeders = (new \DOMXPath($document))->query('//select[starts-with(@name, "items[") and contains(@name, "[feeder_id]")]/option[@selected]');
        $this->assertSame([(string) $this->feeder->id, (string) $this->feeder->id],
            array_map(fn ($option) => $option->getAttribute('value'), iterator_to_array($selectedFeeders)));
        $this->assertDatabaseCount('survey_daily_entries', 0);
    }

    public function test_survey_business_validation_preserves_entered_values(): void
    {
        $data = [
            'entry_date' => today()->toDateString(),
            'remarks' => 'Keep these notes',
            'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 101, 'drive_url' => 'https://drive.google.com/drive/folders/survey-evidence', 'remarks' => 'Over baseline']],
        ];
        $this->actingAs($this->surveyUser)->from('/survey/create')->post('/survey', $data)
            ->assertRedirect('/survey/create')->assertSessionHasErrors('items');
        $this->get('/survey/create')->assertOk()->assertSee('value="101"', false)
            ->assertSee('Keep these notes')->assertSee('Over baseline');
        $this->assertDatabaseCount('survey_daily_entries', 0);
    }

    public function test_login_accepts_active_users_and_rejects_inactive_users(): void
    {
        $this->post('/login', ['email' => $this->surveyUser->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->surveyUser);
        auth()->logout();
        $this->surveyUser->update(['status' => 'inactive']);
        $this->post('/login', ['email' => $this->surveyUser->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_survey_leader_dashboard_excludes_unassigned_feeders(): void
    {
        $otherFeeder = Feeder::create([
            'project_id' => $this->feeder->project_id,
            'circle_id' => $this->feeder->circle_id,
            'division_id' => $this->feeder->division_id,
            'sub_division_id' => $this->feeder->sub_division_id,
            'grid_station_id' => $this->feeder->grid_station_id,
            'feeder_code' => 'F-99',
            'feeder_name' => 'Confidential Other Feeder',
            'total_transformers' => 250,
            'processing_required' => true,
            'status' => 'active',
        ]);
        $otherTeam = SurveyTeam::create(['project_id' => $this->feeder->project_id, 'code' => 'ST2', 'name' => 'Other Survey Team', 'status' => 'active']);
        FeederAssignment::create(['feeder_id' => $otherFeeder->id, 'survey_team_id' => $otherTeam->id, 'assigned_by' => $this->admin->id, 'start_date' => today(), 'status' => 'active']);

        $this->actingAs($this->surveyUser)->get('/')
            ->assertOk()
            ->assertSee('Survey Team Dashboard')
            ->assertSee('F-01')
            ->assertSee('Survey pending')
            ->assertDontSee('MDB')
            ->assertDontSee('F-99')
            ->assertDontSee('Confidential Other Feeder');
        $this->actingAs($this->surveyUser)->get('/mdb/create')->assertForbidden();
    }

    public function test_primary_role_dashboards_and_administration_pages_render(): void
    {
        $this->actingAs($this->admin)->get('/')
            ->assertOk()
            ->assertSee('Management action')
            ->assertSee('Overall delivery progress')
            ->assertSee('Survey status')
            ->assertSee('MDB creation status')
            ->assertSee('Circle-level resource priorities');
        $this->actingAs($this->admin)->get('/admin')->assertOk();
        $this->actingAs($this->admin)->get('/admin/users')->assertOk();
        $this->actingAs($this->admin)->get('/verification')->assertOk()->assertSee('Survey Pending Verification');
        $this->actingAs($this->admin)->get('/mdb/create')->assertOk()->assertSee('Daily MDB Creation');
        $this->actingAs($this->admin)->get('/reports')->assertOk()->assertSee('Overall Project Progress');
        $this->actingAs($this->processor)->get('/')->assertOk()->assertSee('MDB Verification Dashboard');
        $this->actingAs($this->processor)->get('/mdb-verification')->assertOk()->assertSee('MDB Pending Verification');
    }

    public function test_all_required_report_families_render_and_export(): void
    {
        $this->createdMdb(60, 40);
        app(MdbCreationService::class)->verify($this->processor, $this->feeder->mdbItems()->first());
        $returned = app(SurveyProgressService::class)->create($this->surveyUser, $this->surveyTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 10]]]);
        app(SurveyProgressService::class)->returnForCorrection($this->mdbUser, $returned->items->first(), 'Supporting register needs correction.');

        $types = ['daily', 'overall', 'circle', 'division', 'grid_station', 'feeder', 'survey_team', 'pending_verification', 'mdb_creation', 'mdb_creation_backlog', 'mdb_verification', 'mdb_verification_backlog', 'third_party', 'returned_survey', 'returned_mdb'];
        foreach ($types as $type) {
            $this->actingAs($this->admin)->get('/reports?report='.$type)->assertOk();
        }
        $this->actingAs($this->admin)->get('/reports/export.csv?report=feeder')->assertOk()->assertDownload();
    }

    public function test_survey_limit_return_and_resubmission_workflow(): void
    {
        $service = app(SurveyProgressService::class);
        $entry = $service->create($this->surveyUser, $this->surveyTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 80]]]);
        $item = $entry->items->first();
        $service->returnForCorrection($this->mdbUser, $item, 'Quantity needs reconciliation.');
        $this->assertDatabaseCount('notifications', 1);
        $service->resubmit($this->surveyUser, $item->fresh(), ['transformers_surveyed' => 60]);
        $service->verify($this->mdbUser, $item->fresh());
        $this->assertSame(SurveyItemStatus::Verified, $item->fresh()->status);
        $this->assertDatabaseCount('survey_verification_history', 3);

        $this->expectException(ValidationException::class);
        $service->create($this->surveyUser, $this->surveyTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 41]]]);
    }

    public function test_mdb_creation_cannot_exceed_verified_survey(): void
    {
        $this->verifiedSurvey(50);
        $service = app(MdbCreationService::class);
        $service->create($this->mdbUser, $this->mdbTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'mdb_files_created' => 45]]]);
        $this->expectException(ValidationException::class);
        $service->create($this->mdbUser, $this->mdbTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'mdb_files_created' => 6]]]);
    }

    public function test_processing_assignment_reservation_limit_and_organization_isolation(): void
    {
        $this->createdMdb(70, 50);
        $service = app(MdbProcessingService::class);
        $assignment = $service->assign($this->admin, $this->external, ['feeder_id' => $this->feeder->id, 'assigned_quantity' => 40, 'assignment_date' => today()->toDateString()]);
        $other = Organization::create(['name' => 'Processor B', 'type' => 'third_party', 'status' => 'active']);
        $otherProcessor = User::factory()->create(['organization_id' => $other->id, 'role' => UserRole::MdbProcessingUser]);

        try {
            $service->recordProgress($otherProcessor, ['entry_date' => today()->toDateString(), 'items' => [['assignment_id' => $assignment->id, 'mdb_processed' => 1]]]);
            $this->fail('Cross-organization processing was not blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $service->recordProgress($this->processor, ['entry_date' => today()->toDateString(), 'items' => [['assignment_id' => $assignment->id, 'mdb_processed' => 35]]]);
        $this->expectException(ValidationException::class);
        $service->recordProgress($this->processor, ['entry_date' => today()->toDateString(), 'items' => [['assignment_id' => $assignment->id, 'mdb_processed' => 6]]]);
    }

    public function test_assignments_cannot_double_reserve_created_mdb(): void
    {
        $this->createdMdb(50, 50);
        $service = app(MdbProcessingService::class);
        $service->assign($this->admin, $this->external, ['feeder_id' => $this->feeder->id, 'assigned_quantity' => 45, 'assignment_date' => today()->toDateString()]);
        $this->expectException(ValidationException::class);
        $service->assign($this->admin, $this->internal, ['feeder_id' => $this->feeder->id, 'assigned_quantity' => 6, 'assignment_date' => today()->toDateString()]);
    }

    public function test_dashboard_and_backlog_calculations_are_derived_from_transactions(): void
    {
        $this->createdMdb(80, 50);
        app(MdbCreationService::class)->verify($this->processor, $this->feeder->mdbItems()->first());
        $summary = app(DashboardService::class)->summary();
        $this->assertSame(100, $summary['total_transformers']);
        $this->assertSame(80, $summary['survey_verified']);
        $this->assertSame(50, $summary['mdb_created']);
        $this->assertSame(30, $summary['mdb_creation_backlog']);
        $this->assertSame(50, $summary['mdb_verified']);
        $this->assertSame(0, $summary['mdb_verification_pending']);
        $this->assertSame(0, $summary['mdb_verification_backlog']);
        $this->assertSame(0, $summary['mdb_returned']);
    }

    public function test_survey_entry_is_editable_only_by_owner_before_verification(): void
    {
        $service = app(SurveyProgressService::class);
        $data = ['entry_date' => today()->toDateString(), 'remarks' => 'Original', 'items' => [['feeder_id' => (string) $this->feeder->id, 'transformers_surveyed' => 80, 'drive_url' => 'https://drive.google.com/drive/folders/survey-evidence']]];
        $entry = $service->create($this->surveyUser, $this->surveyTeam, $data);
        $item = $entry->items->first();
        $this->actingAs($this->surveyUser)->get('/survey')->assertSee(route('survey.edit', $entry), false);
        $this->get(route('survey.edit', $entry))->assertOk()->assertSee('value="80"', false);
        $other = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->actingAs($other)->get(route('survey.edit', $entry))->assertForbidden();
        $this->put(route('survey.update', $entry), $data)->assertForbidden();
        $data['items'][0]['transformers_surveyed'] = 90;
        $data['items'][0]['drive_url'] = 'https://example.com/correction';
        $data['items'][0]['remarks'] = 'Corrected row';
        $data['remarks'] = 'Corrected header';
        $data['entry_date'] = today()->subDay()->toDateString();
        $this->actingAs($this->surveyUser)->put(route('survey.update', $entry), $data)->assertRedirect(route('survey.index'));
        $this->assertSame(90, $item->fresh()->transformers_surveyed);
        $this->assertSame('Corrected header', $entry->fresh()->remarks);
        $this->assertSame($data['entry_date'], $entry->fresh()->entry_date->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'survey.updated', 'auditable_id' => $entry->id]);
        $service->verify($this->mdbUser, $item->fresh());
        $this->get('/survey')->assertDontSee(route('survey.edit', $entry), false);
        $this->get(route('survey.edit', $entry))->assertForbidden();
        $this->put(route('survey.update', $entry), $data)->assertForbidden();
        $this->actingAs($this->admin)->put(route('survey.update', $entry), $data)->assertForbidden();
        $this->assertSame(90, $item->fresh()->transformers_surveyed);
    }

    public function test_survey_edit_rejects_over_baseline_and_retains_input(): void
    {
        $service = app(SurveyProgressService::class);
        $data = ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 60, 'drive_url' => 'https://drive.google.com/drive/folders/survey-evidence']]];
        $entry = $service->create($this->surveyUser, $this->surveyTeam, $data);
        $service->create($this->surveyUser, $this->surveyTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 30]]]);
        $data['items'][0]['transformers_surveyed'] = 71;
        $this->actingAs($this->surveyUser)->from(route('survey.edit', $entry))->put(route('survey.update', $entry), $data)
            ->assertRedirect(route('survey.edit', $entry))->assertSessionHasErrors('items');
        $this->get(route('survey.edit', $entry))->assertOk()->assertSee('value="71"', false);
        $this->assertSame(60, $entry->items()->first()->transformers_surveyed);
    }

    public function test_mdb_edit_respects_capacity_ownership_and_verification_lock(): void
    {
        $this->verifiedSurvey(70);
        $service = app(MdbCreationService::class);
        $data = ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => (string) $this->feeder->id, 'mdb_files_created' => 40, 'drive_url' => 'https://drive.google.com/drive/folders/mdb-evidence']]];
        $entry = $service->create($this->mdbUser, $this->mdbTeam, $data);
        $item = $entry->items->first();
        $service->create($this->mdbUser, $this->mdbTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'mdb_files_created' => 20]]]);
        $this->actingAs($this->mdbUser)->get('/mdb')->assertSee(route('mdb.edit', $entry), false);
        $this->get(route('mdb.edit', $entry))->assertOk()->assertSee('value="40"', false);
        $other = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::MdbTeamUser]);
        $this->actingAs($other)->get(route('mdb.edit', $entry))->assertForbidden();
        $this->put(route('mdb.update', $entry), $data)->assertForbidden();
        $data['items'][0]['mdb_files_created'] = 51;
        $this->actingAs($this->mdbUser)->from(route('mdb.edit', $entry))->put(route('mdb.update', $entry), $data)->assertSessionHasErrors('items');
        $this->get(route('mdb.edit', $entry))->assertOk()->assertSee('value="51"', false);
        $this->assertSame(40, $item->fresh()->mdb_files_created);
        $data['items'][0]['mdb_files_created'] = 50;
        $data['remarks'] = 'Updated MDB';
        $this->put(route('mdb.update', $entry), $data)->assertRedirect(route('mdb.index'));
        $this->assertSame(50, $item->fresh()->mdb_files_created);
        $this->assertSame('Updated MDB', $entry->fresh()->remarks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.updated', 'auditable_id' => $entry->id]);
        app(MdbProcessingService::class)->assign($this->admin, $this->external, ['feeder_id' => $this->feeder->id, 'assigned_quantity' => 30, 'assignment_date' => today()->toDateString()]);
        $this->get(route('mdb.edit', $entry))->assertOk();
        app(MdbCreationService::class)->verify($this->processor, $item->fresh());
        $this->get('/mdb')->assertDontSee(route('mdb.edit', $entry), false);
        $this->get(route('mdb.edit', $entry))->assertForbidden();
        $this->put(route('mdb.update', $entry), $data)->assertForbidden();
        $this->actingAs($this->admin)->put(route('mdb.update', $entry), $data)->assertForbidden();
        $this->assertSame(50, $item->fresh()->mdb_files_created);
    }

    public function test_created_mdb_appears_in_third_party_verification_without_assignment(): void
    {
        $this->createdMdb(70, 65);
        $this->assertDatabaseCount('mdb_processing_assignments', 0);
        $this->actingAs($this->processor)->get('/mdb-verification')->assertOk()->assertSee('F-01')->assertSee('65');
        $otherOrg = Organization::create(['name' => 'Other Processor', 'type' => 'third_party', 'status' => 'active']);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id, 'role' => UserRole::MdbProcessingUser]);
        $this->actingAs($otherUser)->get('/mdb-verification')->assertOk()->assertSee('F-01');
        $this->actingAs($this->admin)->get('/processing/assignments/create')->assertNotFound();
        $this->actingAs($this->processor)->get('/processing/entries/create')->assertNotFound();
    }

    private function verifiedSurvey(int $quantity): void
    {
        $entry = app(SurveyProgressService::class)->create($this->surveyUser, $this->surveyTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => $quantity]]]);
        app(SurveyProgressService::class)->verify($this->mdbUser, $entry->items->first());
    }

    private function createdMdb(int $verified, int $created): void
    {
        $this->verifiedSurvey($verified);
        app(MdbCreationService::class)->create($this->mdbUser, $this->mdbTeam, ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'mdb_files_created' => $created]]]);
    }
}
