<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $surveyor;
    private Project $project;
    private Project $otherProject;
    private SurveyTeam $team;
    private SurveyTeam $otherTeam;
    private Feeder $firstFeeder;
    private Feeder $secondFeeder;
    private Feeder $inactiveFeeder;
    private Feeder $foreignFeeder;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Organization::create(['name' => 'Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $this->admin = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::SuperAdmin]);
        $this->surveyor = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::SurveyTeamLeader]);
        [$this->project, $this->firstFeeder] = $this->makeProject('P1', 'F-001');
        [$this->otherProject, $this->foreignFeeder] = $this->makeProject('P2', 'F-099');
        $this->secondFeeder = $this->firstFeeder->replicate();
        $this->secondFeeder->fill(['feeder_code' => 'F-002', 'feeder_name' => 'Second Active Feeder'])->save();
        $this->inactiveFeeder = $this->firstFeeder->replicate();
        $this->inactiveFeeder->fill(['feeder_code' => 'F-003', 'feeder_name' => 'Inactive Feeder', 'status' => 'inactive'])->save();
        $this->team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'ST-01', 'name' => 'First Survey Team', 'status' => 'active']);
        $this->otherTeam = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'ST-02', 'name' => 'Second Survey Team', 'status' => 'active']);
    }

    public function test_bulk_assignment_covers_only_active_feeders_in_the_selected_teams_project(): void
    {
        $data = $this->assignmentData();
        $data['end_date'] = today()->addMonth()->toDateString();
        $data['remarks'] = 'Allocate all project feeders to this surveyor.';
        $this->actingAs($this->admin)->from(route('admin.teams.index'))->post(route('admin.teams.assign-feeder'), $data)
            ->assertRedirect(route('admin.teams.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('feeder_assignments', 2);
        foreach ([$this->firstFeeder, $this->secondFeeder] as $feeder) {
            $this->assertDatabaseHas('feeder_assignments', [
                'feeder_id' => $feeder->id,
                'survey_team_id' => $this->team->id,
                'assigned_by' => $this->admin->id,
                'status' => 'active',
                'remarks' => $data['remarks'],
            ]);
            $assignment = FeederAssignment::where('feeder_id', $feeder->id)->where('survey_team_id', $this->team->id)->firstOrFail();
            $this->assertSame($data['start_date'], $assignment->start_date->toDateString());
            $this->assertSame($data['end_date'], $assignment->end_date->toDateString());
        }
        $this->assertDatabaseMissing('feeder_assignments', ['feeder_id' => $this->inactiveFeeder->id]);
        $this->assertDatabaseMissing('feeder_assignments', ['feeder_id' => $this->foreignFeeder->id]);
    }

    public function test_repeated_bulk_assignment_preserves_existing_other_team_and_historical_assignments(): void
    {
        $existing = $this->makeAssignment($this->firstFeeder, $this->team, ['start_date' => today()->subDays(10)->toDateString(), 'remarks' => 'Keep the original assignment.']);
        $other = $this->makeAssignment($this->secondFeeder, $this->otherTeam, ['remarks' => 'Another team still has this feeder.']);
        $historical = $this->makeAssignment($this->secondFeeder, $this->team, ['status' => 'inactive', 'remarks' => 'Retain this historical assignment.']);
        $original = [$existing->fresh()->getRawOriginal(), $other->fresh()->getRawOriginal(), $historical->fresh()->getRawOriginal()];
        $this->actingAs($this->admin)->from(route('admin.teams.index'));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post(route('admin.teams.assign-feeder'), $this->assignmentData())
                ->assertRedirect(route('admin.teams.index'))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('feeder_assignments', 4);
        $this->assertSame(2, FeederAssignment::where('survey_team_id', $this->team->id)->where('status', 'active')->count());
        foreach ([$existing, $other, $historical] as $index => $assignment) {
            $this->assertSame($original[$index], $assignment->fresh()->getRawOriginal());
        }
    }

    public function test_legacy_single_feeder_request_without_assignment_scope_still_works(): void
    {
        $data = $this->assignmentData();
        unset($data['assignment_scope']);
        $data['feeder_id'] = $this->secondFeeder->id;
        $this->actingAs($this->admin)->from(route('admin.teams.index'))->post(route('admin.teams.assign-feeder'), $data)
            ->assertRedirect(route('admin.teams.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('feeder_assignments', 1);
        $this->assertDatabaseHas('feeder_assignments', ['feeder_id' => $this->secondFeeder->id, 'survey_team_id' => $this->team->id, 'assigned_by' => $this->admin->id, 'status' => 'active']);
    }

    public function test_single_assignment_rejects_inactive_missing_or_foreign_project_feeders(): void
    {
        $this->actingAs($this->admin)->from(route('admin.teams.index'));
        foreach ([$this->inactiveFeeder->id, $this->foreignFeeder->id, 999999] as $feederId) {
            $data = $this->assignmentData();
            $data['assignment_scope'] = 'single';
            $data['feeder_id'] = $feederId;
            $this->post(route('admin.teams.assign-feeder'), $data)
                ->assertRedirect(route('admin.teams.index'))->assertSessionHasErrors('feeder_id');
        }
        $this->assertDatabaseCount('feeder_assignments', 0);
    }

    public function test_inactive_team_project_and_invalid_scope_or_dates_cannot_create_assignments(): void
    {
        $this->actingAs($this->admin)->from(route('admin.teams.index'));
        $this->team->update(['status' => 'inactive']);
        $this->post(route('admin.teams.assign-feeder'), $this->assignmentData())->assertSessionHasErrors('survey_team_id');
        $this->team->update(['status' => 'active']);
        $this->project->update(['status' => 'inactive']);
        $this->post(route('admin.teams.assign-feeder'), $this->assignmentData())->assertSessionHasErrors('survey_team_id');
        $this->project->update(['status' => 'active']);
        $data = $this->assignmentData();
        $data['assignment_scope'] = 'invalid';
        $this->post(route('admin.teams.assign-feeder'), $data)->assertSessionHasErrors('assignment_scope');
        $data = $this->assignmentData();
        $data['end_date'] = today()->subDay()->toDateString();
        $this->post(route('admin.teams.assign-feeder'), $data)->assertSessionHasErrors('end_date');
        $this->assertDatabaseCount('feeder_assignments', 0);
    }

    public function test_bulk_assignment_reports_when_the_project_has_no_active_feeders(): void
    {
        $this->firstFeeder->update(['status' => 'inactive']);
        $this->secondFeeder->update(['status' => 'inactive']);
        $this->actingAs($this->admin)->from(route('admin.teams.index'))->post(route('admin.teams.assign-feeder'), $this->assignmentData())
            ->assertRedirect(route('admin.teams.index'))->assertSessionHasErrors('assignment_scope');
        $this->assertDatabaseCount('feeder_assignments', 0);
    }

    public function test_nonadministrators_cannot_write_team_members_or_feeder_assignments(): void
    {
        foreach ([UserRole::SurveyTeamLeader, UserRole::MdbTeamUser, UserRole::ProjectManager, UserRole::ManagementViewer] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('admin.teams.index'))->assertForbidden();
            $this->post(route('admin.teams.assign-feeder'), $this->assignmentData())->assertForbidden();
            $this->post(route('admin.teams.member'), $this->memberData($this->team))->assertForbidden();
        }
        $this->assertDatabaseCount('feeder_assignments', 0);
        $this->assertDatabaseCount('survey_team_members', 0);
    }

    public function test_membership_validates_the_selected_team_type_and_active_user_team_and_project(): void
    {
        $mdbTeam = MdbTeam::forceCreate(['id' => 9000, 'project_id' => $this->project->id, 'code' => 'MDB-01', 'name' => 'MDB Team', 'status' => 'active']);
        $inactiveUser = User::factory()->create(['status' => RecordStatus::Inactive]);
        $this->actingAs($this->admin)->from(route('admin.teams.index'));
        $this->post(route('admin.teams.member'), ['type' => 'survey', 'team_id' => $mdbTeam->id, 'user_id' => $this->surveyor->id])->assertSessionHasErrors('team_id');
        $this->post(route('admin.teams.member'), ['type' => 'mdb', 'team_id' => $this->team->id, 'user_id' => $this->surveyor->id])->assertSessionHasErrors('team_id');
        $data = $this->memberData($this->team);
        $data['user_id'] = $inactiveUser->id;
        $this->post(route('admin.teams.member'), $data)->assertSessionHasErrors('user_id');
        $this->team->update(['status' => 'inactive']);
        $this->post(route('admin.teams.member'), $this->memberData($this->team))->assertSessionHasErrors('team_id');
        $this->team->update(['status' => 'active']);
        $this->project->update(['status' => 'inactive']);
        $this->post(route('admin.teams.member'), $this->memberData($this->team))->assertSessionHasErrors('team_id');
        $this->assertDatabaseCount('survey_team_members', 0);
        $this->assertDatabaseCount('mdb_team_members', 0);
        $this->project->update(['status' => 'active']);
        $this->post(route('admin.teams.member'), ['type' => 'mdb', 'team_id' => $mdbTeam->id, 'user_id' => $this->surveyor->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mdb_team_members', ['mdb_team_id' => $mdbTeam->id, 'user_id' => $this->surveyor->id]);
    }

    public function test_member_and_bulk_assignment_make_all_project_feeders_available_for_survey_entry(): void
    {
        $this->actingAs($this->admin)->from(route('admin.teams.index'))->post(route('admin.teams.member'), $this->memberData($this->team))
            ->assertRedirect(route('admin.teams.index'))->assertSessionHasNoErrors();
        $this->post(route('admin.teams.assign-feeder'), $this->assignmentData())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('survey_team_members', ['survey_team_id' => $this->team->id, 'user_id' => $this->surveyor->id, 'is_leader' => 1]);
        $this->actingAs($this->surveyor)->get('/survey/create')->assertOk()->assertSee('F-001')->assertSee('F-002')
            ->assertDontSee('F-003')->assertDontSee('F-099')->assertDontSee('No feeders assigned');
        $this->post('/survey', $this->surveyData($this->secondFeeder))->assertRedirect(route('survey.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('survey_daily_entries', ['survey_team_id' => $this->team->id, 'entered_by' => $this->surveyor->id]);
        $this->assertDatabaseHas('survey_daily_entry_items', ['feeder_id' => $this->secondFeeder->id, 'transformers_surveyed' => 1]);
    }

    public function test_surveys_can_use_bulk_assigned_feeders_from_a_second_authorized_team(): void
    {
        $this->actingAs($this->admin)->from(route('admin.teams.index'));
        $this->post(route('admin.teams.member'), $this->memberData($this->team))->assertSessionHasNoErrors();
        $this->post(route('admin.teams.member'), $this->memberData($this->otherTeam))->assertSessionHasNoErrors();
        $this->makeAssignment($this->firstFeeder, $this->team);
        $this->post(route('admin.teams.assign-feeder'), $this->assignmentData($this->otherTeam))->assertSessionHasNoErrors();
        $this->actingAs($this->surveyor)->get('/survey/create')->assertOk()->assertSee('F-001')->assertDontSee('F-002');
        $this->get('/survey/create?survey_team_id='.$this->otherTeam->id)->assertOk()->assertSee('F-001')->assertSee('F-002')
            ->assertSee('name="survey_team_id" value="'.$this->otherTeam->id.'"', false);
        $data = $this->surveyData($this->secondFeeder, $this->otherTeam);
        $data['items'][0]['transformers_surveyed'] = 101;
        $this->from('/survey/create')->post('/survey', $data)->assertSessionHasErrors('items');
        $this->get('/survey/create')->assertOk()->assertSee('F-002')->assertSee('value="101"', false)
            ->assertSee('name="survey_team_id" value="'.$this->otherTeam->id.'"', false);
        $data['items'][0]['transformers_surveyed'] = 1;
        $this->post('/survey', $data)->assertRedirect(route('survey.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('survey_daily_entries', ['survey_team_id' => $this->otherTeam->id, 'entered_by' => $this->surveyor->id]);
    }

    public function test_survey_team_selection_cannot_access_another_users_team(): void
    {
        $this->team->members()->attach($this->surveyor->id, ['is_leader' => true]);
        $this->makeAssignment($this->firstFeeder, $this->otherTeam);
        $this->actingAs($this->surveyor)->get('/survey/create?survey_team_id='.$this->otherTeam->id)->assertForbidden();
        $this->post('/survey', $this->surveyData($this->firstFeeder, $this->otherTeam))->assertForbidden();
        $this->assertDatabaseCount('survey_daily_entries', 0);
    }

    public function test_inactive_older_membership_does_not_hide_an_active_survey_team(): void
    {
        $this->team->members()->attach($this->surveyor->id, ['is_leader' => true]);
        $this->otherTeam->members()->attach($this->surveyor->id, ['is_leader' => true]);
        $this->team->update(['status' => 'inactive']);
        $this->makeAssignment($this->secondFeeder, $this->otherTeam);
        $this->actingAs($this->surveyor)->get('/survey/create')->assertOk()->assertSee('F-002')
            ->assertSee('name="survey_team_id" value="'.$this->otherTeam->id.'"', false);
        $this->get('/survey/create?survey_team_id='.$this->team->id)->assertForbidden();
        $this->post('/survey', $this->surveyData($this->secondFeeder))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('survey_daily_entries', ['survey_team_id' => $this->otherTeam->id, 'entered_by' => $this->surveyor->id]);
    }

    public function test_administration_form_offers_all_scope_and_a_team_dropdown(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.teams.index'))
            ->assertOk()->assertSee('All active feeders in this project')->assertSee('name="assignment_scope"', false);
        $this->assertMatchesRegularExpression('/<select\b[^>]*\bname="team_id"/', $response->getContent());
    }

    private function assignmentData(?SurveyTeam $team = null): array
    {
        return ['assignment_scope' => 'all', 'survey_team_id' => ($team ?? $this->team)->id, 'start_date' => today()->toDateString()];
    }

    private function memberData(SurveyTeam $team): array
    {
        return ['type' => 'survey', 'team_id' => $team->id, 'user_id' => $this->surveyor->id, 'is_leader' => 1];
    }

    private function surveyData(Feeder $feeder, ?SurveyTeam $team = null): array
    {
        $data = ['entry_date' => today()->toDateString(), 'items' => [['feeder_id' => $feeder->id, 'transformers_surveyed' => 1, 'drive_url' => 'https://drive.google.com/drive/folders/survey-evidence']]];
        if ($team) {
            $data['survey_team_id'] = $team->id;
        }

        return $data;
    }

    private function makeAssignment(Feeder $feeder, SurveyTeam $team, array $attributes = []): FeederAssignment
    {
        return FeederAssignment::create(array_merge(['feeder_id' => $feeder->id, 'survey_team_id' => $team->id, 'assigned_by' => $this->admin->id, 'start_date' => today()->toDateString(), 'status' => 'active'], $attributes));
    }

    private function makeProject(string $code, string $feederCode): array
    {
        $project = Project::create(['code' => $code, 'name' => 'Project '.$code, 'timezone' => 'Asia/Karachi', 'processing_required' => true, 'status' => 'active']);
        $circle = Circle::create(['project_id' => $project->id, 'code' => 'C1', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'code' => 'D1', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $project->id, 'division_id' => $division->id, 'code' => 'SD1', 'name' => 'Sub Division']);
        $grid = GridStation::create(['project_id' => $project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G1', 'name' => 'Grid']);
        $feeder = Feeder::create(['project_id' => $project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => $feederCode, 'feeder_name' => 'Feeder '.$feederCode, 'total_transformers' => 100, 'baseline_pending' => false, 'processing_required' => true, 'status' => 'active']);

        return [$project, $feeder];
    }
}
