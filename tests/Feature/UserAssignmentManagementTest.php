<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\Mdb\SurveyBatch;
use App\Models\MdbProcessingAssignment;
use App\Models\MdbTeam;
use App\Models\Organization;
use App\Models\ProcessingTeam;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyorAssignment;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\AssignmentOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAssignmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $surveyor;

    private SurveyTeam $team;

    private Feeder $feeder;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Assignment test', 'type' => 'internal', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SuperAdmin]);
        $this->surveyor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Surveyor]);
        $this->project = Project::create(['code' => 'HUB', 'name' => 'Hub project', 'status' => 'active', 'timezone' => 'Asia/Karachi']);
        $circle = Circle::create(['project_id' => $this->project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $sub = SubDivision::create(['project_id' => $this->project->id, 'division_id' => $division->id, 'code' => 'S', 'name' => 'Sub']);
        $grid = GridStation::create(['project_id' => $this->project->id, 'sub_division_id' => $sub->id, 'code' => 'G', 'name' => 'Grid']);
        $this->feeder = Feeder::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $sub->id, 'grid_station_id' => $grid->id, 'feeder_code' => 'HUB-001', 'feeder_name' => 'Hub feeder', 'status' => 'active', 'total_transformers' => 20]);
        $this->team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'ST', 'name' => 'Hub survey team', 'status' => 'active']);
        $this->team->members()->attach($this->surveyor->id, ['is_leader' => true]);
        $this->actingAs($this->admin);
    }

    private function postAssignment(array $data)
    {
        return $this->from(route('admin.assignments.index', ['user_id' => $data['user_id'] ?? $this->surveyor->id]))->post(route('admin.assignments.store'), $data + [
            'user_id' => $this->surveyor->id, 'confirmed' => 1, 'survey_team_id' => $this->team->id,
            'assigned_at' => today()->toDateString(), 'priority' => 'normal',
        ]);
    }

    private function batch(array $data = []): SurveyBatch
    {
        return SurveyBatch::create($data + ['project_id' => $this->project->id, 'feeder_id' => $this->feeder->id, 'survey_team_id' => $this->team->id, 'survey_date' => today(), 'created_by' => $this->admin->id, 'survey_status' => 'entry']);
    }

    private function mobile(array $data = []): SurveyorAssignment
    {
        return SurveyorAssignment::create($data + ['assignment_uuid' => (string) Str::uuid(), 'survey_team_id' => $this->team->id, 'feeder_id' => $this->feeder->id, 'surveyor_id' => $this->surveyor->id, 'assigned_by' => $this->admin->id, 'assigned_at' => today(), 'priority' => 'normal', 'status' => 'assigned']);
    }

    public function test_admin_screen_and_choices_are_read_only_and_show_all_sources_and_history(): void
    {
        $this->mobile();
        $this->newFeeder();
        $this->batch(['entry_assigned_to' => $this->surveyor->id, 'verification_assigned_to' => $this->surveyor->id, 'mdb_assigned_to' => $this->surveyor->id]);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->team->id, 'assigned_by' => $this->admin->id, 'start_date' => today(), 'status' => 'inactive']);
        $mdb = MdbTeam::create(['project_id' => $this->project->id, 'code' => 'MDB', 'name' => 'MDB team', 'status' => 'active']);
        $mdb->members()->attach($this->surveyor->id);
        $processing = ProcessingTeam::create(['project_id' => $this->project->id, 'organization_id' => $this->admin->organization_id, 'code' => 'PT', 'name' => 'Processing team', 'status' => 'active']);
        $processing->members()->attach($this->surveyor->id);
        MdbProcessingAssignment::create(['feeder_id' => $this->feeder->id, 'organization_id' => $this->admin->organization_id, 'processing_team_id' => $processing->id, 'assigned_by' => $this->admin->id, 'assigned_quantity' => 1, 'assignment_date' => today(), 'status' => 'completed']);
        $batch = $this->batch(['entry_assigned_to' => $this->admin->id]);
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'mdb.workflow_task_assigned', 'auditable_type' => SurveyBatch::class, 'auditable_id' => $batch->id, 'old_values' => ['entry_assigned_to' => $this->surveyor->id], 'new_values' => ['entry_assigned_to' => $this->admin->id], 'reason' => 'Previous assignment']);
        $before = $this->snapshot();
        $response = $this->get(route('admin.assignments.index', ['user_id' => $this->surveyor->id]))->assertOk()
            ->assertSee('Mobile App Survey')->assertSee('Data Entry')->assertSee('Data Verification')->assertSee('MDB Creation')
            ->assertSee('Shared through survey team')->assertSee('Previous assignment')->assertSee('Reassigned')->assertSee('Completed');
        $this->assertSame(10, $response->viewData('records')->total());
        $this->getJson(route('admin.assignments.options', ['user_id' => $this->surveyor->id, 'task_type' => 'mobile', 'survey_team_id' => $this->team->id]))->assertOk()->assertJsonPath('items.0.disabled', true);
        $this->assertSame($before, $this->snapshot());
        if ($directory = getenv('ASSIGNMENT_QA_ARTIFACTS')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/assignments.html', $response->getContent());
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['users', 'roles', 'model_has_roles', 'model_has_permissions', 'permissions', 'surveyor_assignments', 'mdb_workflow_batches', 'survey_team_members', 'mdb_team_members', 'processing_team_members', 'feeder_assignments', 'mdb_processing_assignments', 'audit_logs'] as $table) {
            $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public function test_non_admins_cannot_read_or_write_the_hub_or_candidates(): void
    {
        $assignment = $this->mobile();
        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::SuperAdmin) {
                continue;
            }
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->get(route('admin.assignments.index'))->assertForbidden();
            $this->getJson(route('admin.assignments.options', ['user_id' => $this->surveyor->id, 'task_type' => 'mobile']))->assertForbidden();
            $this->getJson(route('admin.assignments.candidates', $assignment))->assertForbidden();
            $this->postAssignment(['task_type' => 'survey_team', 'task_ids' => [$this->team->id]])->assertForbidden();
        }
    }

    public function test_duplicate_mobile_requests_preserve_existing_work_and_membership_flags(): void
    {
        $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id]])->assertSessionHasNoErrors();
        $before = $this->snapshot();
        $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id]])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, '0 allocation(s) created'));
        $this->postAssignment(['task_type' => 'survey_team', 'task_ids' => [$this->team->id]])->assertSessionHasNoErrors();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('survey_team_members', ['user_id' => $this->surveyor->id, 'is_leader' => 1]);
    }

    public function test_started_and_submitted_mobile_work_cannot_be_duplicated(): void
    {
        foreach (['in_progress', 'draft', 'returned', 'submitted'] as $status) {
            $task = $this->mobile(['status' => $status]);
            $before = $this->snapshot();
            $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id]])->assertSessionHasNoErrors();
            $this->assertSame($before, $this->snapshot());
            $task->update(['status' => 'completed']);
        }
    }

    public function test_workflow_reassignment_requires_explicit_replacement_and_preserves_workflow_and_history(): void
    {
        $target = User::factory()->create(['role' => UserRole::SurveyDataEntryOperator]);
        $batch = $this->batch(['entry_assigned_to' => $this->admin->id, 'revision' => 7]);
        $data = ['user_id' => $target->id, 'task_type' => 'entry', 'task_ids' => [$batch->id], 'expected_assignees' => [$batch->id => $this->admin->id]];
        $before = $this->snapshot();
        $this->postAssignment($data)->assertSessionHasErrors('replace_existing');
        $this->assertSame($before, $this->snapshot());
        $original = $batch->fresh()->getRawOriginal();
        $this->postAssignment($data + ['replace_existing' => 1, 'reason' => 'Workload balance'])->assertSessionHasNoErrors();
        $this->assertSame($target->id, $batch->fresh()->entry_assigned_to);
        foreach (['entry_assigned_to', 'updated_at'] as $key) {
            unset($original[$key]);
        }
        $this->assertSame($original, array_diff_key($batch->fresh()->getRawOriginal(), array_flip(['entry_assigned_to', 'updated_at'])));
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdb.workflow_task_assigned', 'reason' => 'Workload balance']);
        $history = app(AssignmentOverview::class)->query($this->admin)->where('source', 'workflow_history')->get();
        $this->assertCount(1, $history);
        $before = $this->snapshot();
        $this->postAssignment(array_replace($data, ['expected_assignees' => [$batch->id => $target->id]]))->assertSessionHasNoErrors();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stale_owner_and_bulk_failure_roll_back_every_assignment_and_success_flash(): void
    {
        $batch = $this->batch();
        $wrongStage = $this->batch(['survey_status' => 'approved']);
        $data = ['task_type' => 'entry', 'user_id' => $this->admin->id, 'task_ids' => [$batch->id, $wrongStage->id], 'expected_assignees' => [$batch->id => 0, $wrongStage->id => 0]];
        $before = $this->snapshot();
        $this->postAssignment($data)->assertSessionHasErrors('task_ids')->assertSessionMissing('success');
        $this->assertSame($before, $this->snapshot());
        $this->postAssignment(array_replace($data, ['task_ids' => [$batch->id], 'expected_assignees' => [$batch->id => $this->admin->id]]))->assertSessionHasErrors('task_ids');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_self_verification_wrong_role_and_missing_confirmation_are_rejected(): void
    {
        $batch = $this->batch(['survey_status' => 'submitted', 'entry_actor_ids' => [$this->admin->id]]);
        $before = $this->snapshot();
        $this->postAssignment(['task_type' => 'verification', 'user_id' => $this->admin->id, 'task_ids' => [$batch->id], 'expected_assignees' => [$batch->id => 0]])->assertSessionHasErrors('user_id');
        $this->postAssignment(['task_type' => 'entry', 'task_ids' => [$batch->id]])->assertSessionHasErrors('user_id');
        $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id], 'confirmed' => 0])->assertSessionHasErrors('confirmed');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_mobile_reassignment_reuses_history_and_started_work_retains_owner(): void
    {
        $target = User::factory()->create(['role' => UserRole::Surveyor]);
        $this->team->members()->attach($target->id);
        $task = $this->mobile(['instructions' => 'Keep instructions']);
        $this->getJson(route('admin.assignments.candidates', $task))->assertOk()->assertJsonPath('users.0.id', $target->id);
        $data = ['task_type' => 'mobile_reassign', 'user_id' => $target->id, 'assignment_id' => $task->id, 'expected_owner' => $this->surveyor->id, 'reason' => 'Balance workload'];
        $this->postAssignment($data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('surveyor_assignments', ['id' => $task->id, 'surveyor_id' => $this->surveyor->id, 'status' => 'reassigned', 'instructions' => 'Keep instructions']);
        $this->assertDatabaseHas('surveyor_assignments', ['surveyor_id' => $target->id, 'status' => 'assigned', 'instructions' => 'Keep instructions']);
        $this->assertDatabaseCount('surveyor_assignments', 2);
        $before = $this->snapshot();
        $this->postAssignment($data)->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
        $started = $this->mobile(['status' => 'in_progress', 'feeder_id' => $this->newFeeder()->id]);
        $this->postAssignment(array_replace($data, ['assignment_id' => $started->id]))->assertSessionHasErrors('assignment');
        $this->assertSame('in_progress', $started->fresh()->status);
    }

    public function test_filters_pagination_inactive_history_and_choice_search(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->mobile(['status' => $i === 0 ? 'completed' : 'reassigned']);
        }
        $response = $this->get(route('admin.assignments.index', ['user_id' => $this->surveyor->id, 'type' => 'mobile']))->assertOk();
        $this->assertSame(27, $response->viewData('records')->total());
        $this->assertCount(25, $response->viewData('records'));
        $response = $this->get(route('admin.assignments.index', ['user_id' => $this->surveyor->id, 'type' => 'mobile', 'status' => 'completed']))->assertOk();
        $this->assertSame(1, $response->viewData('records')->total());
        $this->getJson(route('admin.assignments.options', ['user_id' => $this->surveyor->id, 'task_type' => 'mobile', 'survey_team_id' => $this->team->id, 'task_search' => 'Not found']))->assertOk()->assertJsonCount(0, 'items');
        $this->surveyor->update(['status' => 'inactive']);
        $before = $this->snapshot();
        $this->get(route('admin.assignments.index', ['user_id' => $this->surveyor->id]))->assertOk()->assertSee('Inactive')->assertDontSee('id="assign-form"', false);
        $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id]])->assertSessionHasErrors('user_id');
        $this->assertSame($before, $this->snapshot());
    }

    private function newFeeder(): Feeder
    {
        $feeder = $this->feeder->replicate();
        $feeder->feeder_code = 'NEW-'.Str::random(6);
        $feeder->save();

        return $feeder;
    }

    public function test_all_workflow_stages_and_additional_roles_use_existing_eligibility(): void
    {
        $target = User::factory()->create(['role' => UserRole::ManagementViewer]);
        foreach (['entry' => [UserRole::SurveyDataEntryOperator, 'entry'], 'verification' => [UserRole::SurveyDataVerifier, 'submitted'], 'mdb' => [UserRole::MdbGenerator, 'approved']] as $type => [$role, $status]) {
            $target->assignRole(Role::findOrCreate($role->value, 'web'));
            $batch = $this->batch(['survey_status' => $status]);
            $this->getJson(route('admin.assignments.options', ['user_id' => $target->id, 'task_type' => $type]))->assertOk()->assertJsonPath('items.0.id', $batch->id);
            $this->postAssignment(['task_type' => $type, 'user_id' => $target->id, 'task_ids' => [$batch->id], 'expected_assignees' => [$batch->id => 0]])->assertSessionHasNoErrors();
            $field = AssignmentOverview::FIELDS[$type];
            $this->assertSame($target->id, $batch->fresh()->$field);
            $this->assertSame($status, $batch->fresh()->survey_status);
        }
        $this->assertSame(UserRole::ManagementViewer, $target->fresh()->role);
    }

    public function test_mobile_team_scope_overlap_and_bulk_rollback_are_enforced(): void
    {
        $other = User::factory()->create(['role' => UserRole::Surveyor]);
        $existing = $this->mobile(['surveyor_id' => $other->id, 'status' => 'in_progress']);
        $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id]])->assertSessionHasErrors('authorize_overlap');
        $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id], 'authorize_overlap' => 1])->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $existing->fresh()->status);
        $next = $this->newFeeder();
        $inactive = $this->newFeeder();
        $inactive->update(['status' => 'inactive']);
        $before = $this->snapshot();
        $this->postAssignment(['task_type' => 'feeder_pool', 'task_ids' => [$next->id, $inactive->id]])->assertSessionHasErrors()->assertSessionMissing('success');
        $this->assertSame($before, $this->snapshot());
        $this->postAssignment(['task_type' => 'mobile', 'user_id' => $other->id, 'task_ids' => [$next->id]])->assertSessionHasErrors('survey_team_id');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_team_bulk_assignment_does_not_modify_permissions_or_existing_membership(): void
    {
        $team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'SECOND', 'name' => 'Second team', 'status' => 'active']);
        $userBefore = $this->surveyor->fresh()->getRawOriginal();
        $rolesBefore = DB::table('model_has_roles')->get()->toJson();
        $membershipBefore = DB::table('survey_team_members')->where('survey_team_id', $this->team->id)->first();
        $this->postAssignment(['task_type' => 'survey_team', 'task_ids' => [$this->team->id, $team->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('survey_team_members', 2);
        $this->assertEquals($membershipBefore, DB::table('survey_team_members')->where('survey_team_id', $this->team->id)->first());
        $this->assertSame($userBefore, $this->surveyor->fresh()->getRawOriginal());
        $this->assertSame($rolesBefore, DB::table('model_has_roles')->get()->toJson());
        $this->assertDatabaseCount('audit_logs', 1);
        $mdbUser = User::factory()->create(['role' => UserRole::MdbGenerator]);
        $mdbTeam = MdbTeam::create(['project_id' => $this->project->id, 'code' => 'MDB', 'name' => 'MDB team', 'status' => 'active']);
        $this->postAssignment(['task_type' => 'mdb_team', 'user_id' => $mdbUser->id, 'task_ids' => [$mdbTeam->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mdb_team_members', ['mdb_team_id' => $mdbTeam->id, 'user_id' => $mdbUser->id]);
    }

    public function test_large_choice_lists_are_paginated_and_duplicate_ids_are_rejected(): void
    {
        for ($i = 0; $i < 32; $i++) {
            $this->newFeeder();
        }
        $filters = ['user_id' => $this->surveyor->id, 'task_type' => 'mobile', 'survey_team_id' => $this->team->id];
        $this->getJson(route('admin.assignments.options', $filters))->assertOk()->assertJsonCount(30, 'items')->assertJsonPath('more', true);
        $this->getJson(route('admin.assignments.options', $filters + ['choice_page' => 2]))->assertOk()->assertJsonCount(3, 'items')->assertJsonPath('more', false);
        $before = $this->snapshot();
        $this->postAssignment(['task_type' => 'mobile', 'task_ids' => [$this->feeder->id, $this->feeder->id]])->assertSessionHasErrors('task_ids.0');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_disabled_processing_workflow_is_not_reenabled_by_the_hub(): void
    {
        $this->get('/processing/assignments/create')->assertNotFound();
        $before = $this->snapshot();
        $this->postAssignment(['task_type' => 'processing', 'task_ids' => [$this->feeder->id]])->assertSessionHasErrors('task_type');
        $this->assertSame($before, $this->snapshot());
    }
}
