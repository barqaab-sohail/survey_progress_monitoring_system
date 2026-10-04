<?php

namespace Tests\Feature;

use App\Enums\SurveyItemStatus;
use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\MdbDailyEntry;
use App\Models\MdbTeam;
use App\Models\Organization;
use App\Models\ProcessingTeam;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\MdbCreationService;
use App\Services\SurveyProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdbVerificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $internal;
    private Organization $thirdParty;
    private User $admin;
    private User $surveyLeader;
    private User $mdbAuthor;
    private User $reviewer;
    private SurveyTeam $surveyTeam;
    private MdbTeam $mdbTeam;
    private Feeder $feeder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->internal = Organization::create(['name' => 'Internal Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $this->thirdParty = Organization::create(['name' => 'Third Party Verification', 'type' => 'third_party', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::SuperAdmin]);
        $this->surveyLeader = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::SurveyTeamLeader]);
        $this->mdbAuthor = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::MdbTeamUser]);
        $this->reviewer = User::factory()->create(['organization_id' => $this->thirdParty->id, 'role' => UserRole::MdbProcessingUser]);
        $project = Project::create(['code' => 'P1', 'name' => 'Project', 'timezone' => 'Asia/Karachi', 'processing_required' => false, 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C1', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D1', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD1', 'name' => 'Sub Division']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G1', 'name' => 'Grid']);
        $this->feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'F-01', 'feeder_name' => 'Verification Feeder', 'total_transformers' => 100, 'baseline_pending' => false, 'processing_required' => false, 'status' => 'active']);
        $this->surveyTeam = SurveyTeam::create(['project_id' => $project->id, 'code' => 'ST1', 'name' => 'Survey Team', 'status' => 'active']);
        $this->surveyTeam->members()->attach($this->surveyLeader->id, ['is_leader' => true]);
        $this->mdbTeam = MdbTeam::create(['project_id' => $project->id, 'code' => 'MT1', 'name' => 'MDB Team', 'status' => 'active']);
        $this->mdbTeam->members()->attach($this->mdbAuthor->id);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->surveyTeam->id, 'assigned_by' => $this->admin->id, 'start_date' => today(), 'status' => 'active']);
        $this->verifySurvey($this->feeder, 70);
    }

    public function test_mdb_return_correction_and_verification_preserve_history_and_audit(): void
    {
        $entry = $this->createMdb(40, ['remarks' => 'Original MDB evidence']);
        $item = $entry->items->first();
        $returnUrl = '/mdb-verification/items/'.$item->id.'/return';
        $verifyUrl = '/mdb-verification/items/'.$item->id.'/verify';
        $resubmitUrl = '/mdb/items/'.$item->id.'/resubmit';

        $this->assertSame(SurveyItemStatus::Submitted, $item->status);
        $this->actingAs($this->reviewer)->get('/mdb-verification')->assertOk()->assertSee('Original MDB evidence');
        $this->from('/mdb-verification')->post($returnUrl, [])
            ->assertRedirect('/mdb-verification')->assertSessionHasErrors('reason');
        $this->assertSame(SurveyItemStatus::Submitted, $item->fresh()->status);
        $this->assertDatabaseCount('mdb_verification_histories', 0);

        $this->post($returnUrl, ['reason' => 'Please replace the unreadable MDB file.'])->assertRedirect();
        $this->assertSame(SurveyItemStatus::Returned, $item->fresh()->status);
        $this->assertSame('Please replace the unreadable MDB file.', $item->fresh()->return_reason);
        $this->get('/mdb-verification')->assertDontSee('Original MDB evidence');
        $this->actingAs($this->mdbAuthor)->get('/mdb/returned')->assertOk()->assertSee('Please replace the unreadable MDB file.');
        $this->put($resubmitUrl, ['correction_item_id' => $item->id, 'mdb_files_created' => 35, 'drive_url' => 'https://example.com/corrected-mdb', 'remarks' => 'Replaced MDB evidence'])
            ->assertRedirect('/mdb/returned');
        $this->assertSame(SurveyItemStatus::Submitted, $item->fresh()->status);
        $this->assertSame(35, $item->fresh()->mdb_files_created);
        $this->assertNotNull($item->fresh()->resubmitted_at);
        $this->assertNull($item->fresh()->return_reason);

        $this->actingAs($this->reviewer)->get('/mdb-verification')->assertOk()->assertSee('Replaced MDB evidence');
        $this->post($verifyUrl)->assertRedirect();
        $this->assertSame(SurveyItemStatus::Verified, $item->fresh()->status);
        $this->assertSame($this->reviewer->id, $item->fresh()->verified_by);
        $this->assertNotNull($item->fresh()->verified_at);
        $this->get('/mdb-verification')->assertDontSee('Replaced MDB evidence');
        $this->get('/mdb-verification/history')->assertOk()->assertSee('Please replace the unreadable MDB file.');
        $this->assertDatabaseCount('mdb_daily_entry_items', 1);
        $this->assertDatabaseCount('mdb_verification_histories', 3);
        foreach ([['returned', 40], ['resubmitted', 35], ['verified', 35]] as [$action, $quantity]) {
            $this->assertDatabaseHas('mdb_verification_histories', ['mdb_daily_entry_item_id' => $item->id, 'action' => $action, 'quantity_snapshot' => $quantity]);
            $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.'.$action, 'auditable_id' => $item->id]);
        }
    }

    public function test_returned_mdb_keeps_reserved_capacity_and_resubmission_never_duplicates_counts(): void
    {
        $entry = $this->createMdb(40);
        $item = $entry->items->first();
        $this->createMdb(20);
        app(MdbCreationService::class)->returnForCorrection($this->reviewer, $item, 'Correct the quantity.');

        $this->actingAs($this->mdbAuthor)->from('/mdb/create')->post('/mdb', $this->mdbData(11))
            ->assertRedirect('/mdb/create')->assertSessionHasErrors('items');
        $this->assertDatabaseCount('mdb_daily_entries', 2);
        $this->assertDatabaseCount('mdb_daily_entry_items', 2);
        $summary = app(DashboardService::class)->summary();
        $this->assertSame(60, $summary['mdb_created']);
        $this->assertSame(40, $summary['mdb_returned']);
        $this->assertSame(20, $summary['mdb_verification_pending']);
        $this->assertSame(60, $summary['mdb_verification_backlog']);

        $correction = ['correction_item_id' => $item->id, 'mdb_files_created' => 51, 'drive_url' => 'https://example.com/retain-link', 'remarks' => 'Keep correction notes'];
        $this->from('/mdb/returned')->put('/mdb/items/'.$item->id.'/resubmit', $correction)
            ->assertRedirect('/mdb/returned')->assertSessionHasErrors('mdb_files_created')
            ->assertSessionHasInput('mdb_files_created', 51)->assertSessionHasInput('drive_url', $correction['drive_url'])
            ->assertSessionHasInput('remarks', $correction['remarks']);
        $this->get('/mdb/returned')->assertOk()->assertSee('value="51"', false)
            ->assertSee($correction['drive_url'])->assertSee('Keep correction notes');
        $this->assertSame(40, $item->fresh()->mdb_files_created);
        $this->assertSame(SurveyItemStatus::Returned, $item->fresh()->status);

        $correction['mdb_files_created'] = 50;
        $this->put('/mdb/items/'.$item->id.'/resubmit', $correction)->assertRedirect('/mdb/returned');
        $this->assertDatabaseCount('mdb_daily_entry_items', 2);
        $summary = app(DashboardService::class)->summary();
        $this->assertSame(70, $summary['mdb_created']);
        $this->assertSame(70, $summary['mdb_verification_pending']);
        $this->assertSame(0, $summary['mdb_returned']);
        $this->assertDatabaseCount('mdb_verification_histories', 2);
    }

    public function test_one_reviewed_row_locks_the_entire_entry_and_stale_update_cannot_overwrite_it(): void
    {
        $otherFeeder = $this->feeder->replicate();
        $otherFeeder->feeder_code = 'F-02';
        $otherFeeder->save();
        FeederAssignment::create(['feeder_id' => $otherFeeder->id, 'survey_team_id' => $this->surveyTeam->id, 'assigned_by' => $this->admin->id, 'start_date' => today(), 'status' => 'active']);
        $this->verifySurvey($otherFeeder, 30);
        $data = $this->mdbData(40);
        $data['items'][] = ['feeder_id' => $otherFeeder->id, 'mdb_files_created' => 20];
        $entry = app(MdbCreationService::class)->create($this->mdbAuthor, $this->mdbTeam, $data);
        $item = $entry->items->first();
        $this->actingAs($this->mdbAuthor)->get(route('mdb.edit', $entry))->assertOk();

        app(MdbCreationService::class)->returnForCorrection($this->reviewer, $item, 'Please correct this one row.');
        $this->assertFalse($entry->fresh()->canBeEdited());
        $this->get(route('mdb.edit', $entry))->assertForbidden();
        $data['items'][0]['mdb_files_created'] = 45;
        $this->put(route('mdb.update', $entry), $data)->assertForbidden();
        $this->actingAs($this->admin)->put(route('mdb.update', $entry), $data)->assertForbidden();
        $this->assertSame(40, $item->fresh()->mdb_files_created);
        $this->assertSame(20, $entry->items()->orderBy('id')->get()->last()->mdb_files_created);
        $this->assertDatabaseCount('mdb_verification_histories', 1);
    }

    public function test_other_mdb_author_cannot_view_or_correct_a_returned_item(): void
    {
        $entry = $this->createMdb(40);
        $item = $entry->items->first();
        app(MdbCreationService::class)->returnForCorrection($this->reviewer, $item, 'Private correction for the author.');
        $otherAuthor = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::MdbTeamUser]);
        $this->mdbTeam->members()->attach($otherAuthor->id);
        $this->actingAs($otherAuthor)->get('/mdb/returned')->assertOk()->assertDontSee('Private correction for the author.');
        $this->put('/mdb/items/'.$item->id.'/resubmit', ['mdb_files_created' => 30])->assertForbidden();
        $this->get(route('mdb.edit', $entry))->assertForbidden();
        $this->assertSame(SurveyItemStatus::Returned, $item->fresh()->status);
        $this->assertSame(40, $item->fresh()->mdb_files_created);
        $this->assertDatabaseCount('mdb_verification_histories', 1);
    }

    public function test_internal_processor_and_other_workflow_roles_cannot_review_mdb(): void
    {
        $item = $this->createMdb(40)->items->first();
        $internalProcessor = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::MdbProcessingUser]);
        foreach ([$internalProcessor, $this->surveyLeader, $this->mdbAuthor] as $user) {
            $this->actingAs($user)->get('/mdb-verification')->assertForbidden();
            $this->get('/mdb-verification/history')->assertForbidden();
            $this->post('/mdb-verification/items/'.$item->id.'/verify')->assertForbidden();
            $this->post('/mdb-verification/items/'.$item->id.'/return', ['reason' => 'An unauthorized return.'])->assertForbidden();
        }
        $this->assertSame(SurveyItemStatus::Submitted, $item->fresh()->status);
        $this->assertDatabaseCount('mdb_verification_histories', 0);
    }

    public function test_third_party_role_can_only_review_mdb_in_the_workflow(): void
    {
        $this->actingAs($this->reviewer)->get('/mdb-verification')->assertOk();
        foreach (['/survey', '/survey/create', '/survey/returned', '/verification', '/mdb', '/mdb/create', '/mdb/returned'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/mdb', $this->mdbData(1))->assertForbidden();
        $this->post('/survey', ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'transformers_surveyed' => 1]]])->assertForbidden();
        foreach (['/processing/entries', '/processing/entries/create', '/processing/assignments', '/processing/assignments/create'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->post('/processing/entries', ['items' => []])->assertNotFound();
        $this->post('/processing/assignments', ['feeder_id' => $this->feeder->id])->assertNotFound();
        $this->assertDatabaseCount('mdb_daily_entries', 0);
    }

    public function test_inactive_third_party_organization_cannot_review_mdb(): void
    {
        $item = $this->createMdb(40)->items->first();
        $this->thirdParty->update(['status' => 'inactive']);
        $this->actingAs($this->reviewer)->get('/mdb-verification')->assertForbidden();
        $this->post('/mdb-verification/items/'.$item->id.'/verify')->assertForbidden();
        $this->post('/mdb-verification/items/'.$item->id.'/return', ['reason' => 'Inactive organization review.'])->assertForbidden();
        $this->assertSame(SurveyItemStatus::Submitted, $item->fresh()->status);
    }

    public function test_administrator_cannot_verify_their_own_mdb_entry(): void
    {
        $entry = app(MdbCreationService::class)->create($this->admin, null, $this->mdbData(40));
        $item = $entry->items->first();
        $this->actingAs($this->admin)->post('/mdb-verification/items/'.$item->id.'/verify')->assertForbidden();
        $this->post('/mdb-verification/items/'.$item->id.'/return', ['reason' => 'Self review should be rejected.'])->assertForbidden();
        $this->assertSame(SurveyItemStatus::Submitted, $item->fresh()->status);
        $this->assertDatabaseCount('mdb_verification_histories', 0);
        $this->actingAs($this->reviewer)->post('/mdb-verification/items/'.$item->id.'/verify')->assertRedirect();
        $this->assertSame(SurveyItemStatus::Verified, $item->fresh()->status);
    }

    public function test_mdb_creation_validation_preserves_all_entered_fields(): void
    {
        $data = $this->mdbData(71);
        $data['entry_date'] = today()->subDay()->toDateString();
        $data['remarks'] = 'Preserve the entry header';
        $data['items'][0]['drive_url'] = 'https://example.com/original-mdb';
        $data['items'][0]['remarks'] = 'Preserve the feeder notes';
        $this->actingAs($this->mdbAuthor)->from('/mdb/create')->post('/mdb', $data)
            ->assertRedirect('/mdb/create')->assertSessionHasErrors('items')->assertSessionHasInput('items', $data['items']);
        $this->get('/mdb/create')->assertOk()->assertSee('value="'.$data['entry_date'].'"', false)
            ->assertSee('value="71"', false)->assertSee('Preserve the entry header')
            ->assertSee('https://example.com/original-mdb')->assertSee('Preserve the feeder notes');
        $this->assertDatabaseCount('mdb_daily_entries', 0);
    }

    public function test_replayed_review_and_resubmission_requests_cannot_change_completed_states(): void
    {
        $item = $this->createMdb(40)->items->first();
        $returnUrl = '/mdb-verification/items/'.$item->id.'/return';
        $verifyUrl = '/mdb-verification/items/'.$item->id.'/verify';
        $this->actingAs($this->reviewer)->from('/mdb-verification')->post($returnUrl, ['reason' => 'Replace the attached MDB file.'])->assertRedirect();
        $this->post($verifyUrl)->assertSessionHasErrors('status');
        $this->post($returnUrl, ['reason' => 'A replayed return.'])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('mdb_verification_histories', 1);
        $this->assertSame('Replace the attached MDB file.', $item->fresh()->return_reason);

        $correction = ['correction_item_id' => $item->id, 'mdb_files_created' => 35];
        $this->actingAs($this->mdbAuthor)->put('/mdb/items/'.$item->id.'/resubmit', $correction)->assertRedirect();
        $correction['mdb_files_created'] = 30;
        $this->put('/mdb/items/'.$item->id.'/resubmit', $correction)->assertForbidden();
        $this->actingAs($this->reviewer)->from('/mdb-verification')->post($verifyUrl)->assertRedirect();
        $this->post($verifyUrl)->assertSessionHasErrors('status');
        $this->post($returnUrl, ['reason' => 'Attempt to reopen completed review.'])->assertSessionHasErrors('status');
        $this->actingAs($this->mdbAuthor)->put('/mdb/items/'.$item->id.'/resubmit', $correction)->assertForbidden();
        $this->assertSame(SurveyItemStatus::Verified, $item->fresh()->status);
        $this->assertSame(35, $item->fresh()->mdb_files_created);
        $this->assertDatabaseCount('mdb_verification_histories', 3);
    }

    public function test_delivery_requires_third_party_verification_even_for_legacy_optional_processing_feeders(): void
    {
        $this->feeder->update(['total_transformers' => 70, 'processing_required' => false]);
        $item = $this->createMdb(70)->items->first();
        $dashboard = app(DashboardService::class);
        $this->assertSame('MDB VERIFICATION PENDING', $dashboard->feederProgress()->first()->progress_status);
        $this->assertSame(0, $dashboard->summary()['mdb_verified']);

        app(MdbCreationService::class)->returnForCorrection($this->reviewer, $item, 'Replace the evidence before completion.');
        $this->assertSame('MDB RETURNED', $dashboard->feederProgress()->first()->progress_status);
        app(MdbCreationService::class)->resubmit($this->mdbAuthor, $item->fresh(), ['mdb_files_created' => 70]);
        app(MdbCreationService::class)->verify($this->reviewer, $item->fresh());
        $this->assertSame('COMPLETED', $dashboard->feederProgress()->first()->progress_status);
        $this->assertSame(70, $dashboard->summary()['mdb_verified']);
        $this->assertSame(100.0, $dashboard->summary()['percentages']['mdb_verified']);
        $performance = $dashboard->processingOrganizationPerformance();
        $this->assertCount(1, $performance);
        $this->assertSame($this->thirdParty->id, (int) $performance->first()->id);
        $this->assertSame(70, (int) $performance->first()->verified);
        $this->assertSame(70, (int) $performance->first()->returned);
    }

    public function test_administrator_cannot_create_or_update_a_third_party_processor_with_an_internal_organization(): void
    {
        $data = [
            'organization_id' => $this->internal->id,
            'name' => 'New Third-Party Reviewer',
            'email' => 'new-reviewer@example.test',
            'role' => UserRole::MdbProcessingUser->value,
            'password' => 'TestPassword123!',
            'password_confirmation' => 'TestPassword123!',
        ];
        $this->actingAs($this->admin)->from(route('admin.users.index'))->post(route('admin.users.store'), $data)
            ->assertRedirect(route('admin.users.index'))->assertSessionHasErrors('organization_id');
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);

        $data['organization_id'] = $this->thirdParty->id;
        $this->post(route('admin.users.store'), $data)->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'organization_id' => $this->thirdParty->id, 'role' => UserRole::MdbProcessingUser->value, 'status' => 'active']);

        $data['organization_id'] = $this->internal->id;
        $data['status'] = 'active';
        unset($data['password'], $data['password_confirmation']);
        $this->put(route('admin.users.update', $user), $data)->assertSessionHasErrors('organization_id');
        $this->assertSame($this->thirdParty->id, $user->fresh()->organization_id);
        $data['organization_id'] = $this->thirdParty->id;
        $data['name'] = 'Updated Third-Party Reviewer';
        $this->put(route('admin.users.update', $user), $data)->assertRedirect(route('admin.users.index'))->assertSessionHasNoErrors();
        $this->assertSame($data['name'], $user->fresh()->name);
        $this->assertSame($this->thirdParty->id, $user->fresh()->organization_id);
    }

    public function test_retirement_migration_deactivates_only_internal_processors_and_preserves_history(): void
    {
        $internalProcessor = User::factory()->create(['organization_id' => $this->internal->id, 'role' => UserRole::MdbProcessingUser]);
        $internalTeam = ProcessingTeam::create(['project_id' => $this->feeder->project_id, 'organization_id' => $this->internal->id, 'code' => 'INTERNAL-OLD', 'name' => 'Legacy Internal Team', 'status' => 'active']);
        $thirdPartyTeam = ProcessingTeam::create(['project_id' => $this->feeder->project_id, 'organization_id' => $this->thirdParty->id, 'code' => 'EXTERNAL-OLD', 'name' => 'Legacy Third Party Team', 'status' => 'active']);
        $item = $this->createMdb(40)->items->first();
        app(MdbCreationService::class)->verify($this->reviewer, $item);

        $migration = require database_path('migrations/2026_10_04_000200_retire_internal_mdb_processors.php');
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $internalProcessor->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('processing_teams', ['id' => $internalTeam->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('users', ['id' => $this->reviewer->id, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['id' => $this->mdbAuthor->id, 'status' => 'active']);
        $this->assertDatabaseHas('processing_teams', ['id' => $thirdPartyTeam->id, 'status' => 'active']);
        $this->assertDatabaseHas('mdb_daily_entry_items', ['id' => $item->id, 'status' => 'verified', 'verified_by' => $this->reviewer->id]);
        $this->assertDatabaseCount('mdb_verification_histories', 1);
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $internalProcessor->id, 'status' => 'inactive']);
    }

    public function test_default_accounts_include_only_third_party_processors(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'internal@hazeco.test']);
        $this->assertFalse(User::where('role', UserRole::MdbProcessingUser->value)
            ->whereHas('organization', fn ($query) => $query->where('type', 'internal'))->exists());
        $this->assertDatabaseHas('users', ['email' => 'thirdparty@hazeco.test', 'role' => UserRole::MdbProcessingUser->value, 'name' => 'Third-Party Processor', 'status' => 'active']);
        $this->assertSame('MDB User', UserRole::MdbTeamUser->label());
        $this->assertSame('Third-Party Processor', UserRole::MdbProcessingUser->label());
    }

    private function verifySurvey(Feeder $feeder, int $quantity): void
    {
        $entry = app(SurveyProgressService::class)->create($this->surveyLeader, $this->surveyTeam, [
            'entry_date' => today()->toDateString(),
            'items' => [['feeder_id' => $feeder->id, 'transformers_surveyed' => $quantity]],
        ]);
        app(SurveyProgressService::class)->verify($this->mdbAuthor, $entry->items->first());
    }

    private function mdbData(int $quantity): array
    {
        return ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $this->feeder->id, 'mdb_files_created' => $quantity]]];
    }

    private function createMdb(int $quantity, array $row = []): MdbDailyEntry
    {
        $data = $this->mdbData($quantity);
        $data['items'][0] = array_merge($data['items'][0], $row);

        return app(MdbCreationService::class)->create($this->mdbAuthor, $this->mdbTeam, $data);
    }
}
