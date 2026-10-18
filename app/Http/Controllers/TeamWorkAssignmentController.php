<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\FieldSurvey;
use App\Models\Mdb\SurveyBatch;
use App\Models\MdbTeam;
use App\Models\SurveyTeam;
use App\Models\SurveyorAssignment;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamWorkAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $teams = $this->teams($request->user())->with(['project', 'members'])->get();
        $userIds = $teams->flatMap(fn ($team) => $team->members->pluck('id'))->unique();
        $feeders = Feeder::active()->whereIn('project_id', $teams->pluck('project_id'))->orderBy('feeder_code')->get();
        $users = User::whereIn('id', $userIds)->where('status', 'active')->orderBy('name')->get()->keyBy('id');
        $workload = SurveyorAssignment::selectRaw('surveyor_id, count(*) as assigned_count')
            ->whereIn('status', ['assigned', 'in_progress', 'draft', 'returned'])->whereIn('surveyor_id', $userIds)->groupBy('surveyor_id')->pluck('assigned_count', 'surveyor_id');
        $teamOptions = $teams->mapWithKeys(function (SurveyTeam $team) use ($users, $feeders, $workload) {
            $members = $team->members->filter(fn (User $member) => $users->has($member->id)
                && $member->hasAnyRole([UserRole::Surveyor->value, UserRole::SurveyTeamLeader->value]))
                ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name, 'role' => $member->role->label(), 'open_tasks' => $workload[$member->id] ?? 0])->values();
            $teamFeeders = $feeders->where('project_id', $team->project_id)
                ->map(fn (Feeder $feeder) => ['id' => $feeder->id, 'code' => $feeder->feeder_code, 'name' => $feeder->feeder_name])->values();
            return [$team->id => ['members' => $members, 'feeders' => $teamFeeders]];
        });
        $assignments = SurveyorAssignment::with(['team.project', 'feeder', 'surveyor', 'assigner'])
            ->whereIn('survey_team_id', $teams->modelKeys())->latest()->paginate(40);
        $progressCounts = SurveyorAssignment::selectRaw('status, count(*) as total')->whereIn('survey_team_id', $teams->modelKeys())->groupBy('status')->pluck('total', 'status');
        return view('team-work.assignments', compact('teams', 'assignments', 'workload', 'teamOptions', 'progressCounts'));
    }

    public function assignSurvey(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'survey_team_id' => ['required', 'integer', 'exists:survey_teams,id'],
            'surveyor_id' => ['required', 'integer', 'exists:users,id'],
            'feeder_ids' => ['required', 'array', 'min:1'], 'feeder_ids.*' => ['required', 'integer', 'distinct', 'exists:feeders,id'],
            'assigned_at' => ['required', 'date'], 'deadline' => ['nullable', 'date', 'after_or_equal:assigned_at'],
            'priority' => ['required', 'in:low,normal,high,urgent'], 'instructions' => ['nullable', 'string', 'max:2000'],
            'authorize_overlap' => ['sometimes', 'accepted'],
        ]);
        $team = $this->teams($request->user())->whereKey($data['survey_team_id'])->firstOrFail();
        if (! $team->members()->whereKey($data['surveyor_id'])->exists()) {
            throw ValidationException::withMessages(['surveyor_id' => 'Choose an active surveyor who belongs to the selected survey team.']);
        }
        $surveyor = User::findOrFail($data['surveyor_id']);
        if (! $surveyor->isActive() || ! $surveyor->hasAnyRole([UserRole::Surveyor->value, UserRole::SurveyTeamLeader->value, UserRole::SuperAdmin->value])) {
            throw ValidationException::withMessages(['surveyor_id' => 'Choose an active surveyor or a team leader authorized to conduct surveys.']);
        }

        $created = DB::transaction(function () use ($request, $data, $team, $audit) {
            $lockedTeam = SurveyTeam::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $feeders = Feeder::active()->where('project_id', $lockedTeam->project_id)->whereIn('id', $data['feeder_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($feeders->count() !== count($data['feeder_ids'])) throw ValidationException::withMessages(['feeder_ids' => 'All selected feeders must be active and belong to the selected project.']);
            $records = [];
            foreach ($feeders as $feeder) {
                $teamFeeder = FeederAssignment::where('feeder_id', $feeder->id)->where('survey_team_id', $lockedTeam->id)->where('status', 'active')->first();
                if (! $teamFeeder) {
                    FeederAssignment::create(['feeder_id' => $feeder->id, 'survey_team_id' => $lockedTeam->id,
                        'assigned_by' => $request->user()->id, 'start_date' => $data['assigned_at'], 'end_date' => $data['deadline'] ?? null,
                        'remarks' => 'Allocated through individual survey assignment.', 'status' => 'active']);
                }
                $existing = SurveyorAssignment::where('feeder_id', $feeder->id)->where('status', 'assigned')->lockForUpdate()->get();
                $overlap = $existing->contains(fn ($assignment) => $assignment->surveyor_id !== (int) $data['surveyor_id']);
                if ($overlap && ! $request->boolean('authorize_overlap')) {
                    throw ValidationException::withMessages(['authorize_overlap' => 'A selected feeder is already assigned to another surveyor. Deliberately authorize overlap to proceed.']);
                }
                $record = SurveyorAssignment::firstOrNew(['survey_team_id' => $lockedTeam->id, 'feeder_id' => $feeder->id, 'surveyor_id' => $data['surveyor_id'], 'status' => 'assigned']);
                if ($record->exists) throw ValidationException::withMessages(['feeder_ids' => 'One or more selected assignments already exist. Use Reassign for work already assigned.']);
                $record->fill(['assignment_uuid' => (string) Str::uuid(), 'assigned_by' => $request->user()->id,
                    'assigned_at' => $data['assigned_at'], 'deadline' => $data['deadline'] ?? null, 'priority' => $data['priority'],
                    'instructions' => $data['instructions'] ?? null, 'status' => 'assigned'])->save();
                $records[] = $record;
            }
            $audit->record($request->user(), 'survey.tasks_assigned', $lockedTeam, [], ['assignment_ids' => collect($records)->pluck('id'),
                'surveyor_id' => (int) $data['surveyor_id'], 'feeder_ids' => $feeders->modelKeys(), 'assigned_at' => $data['assigned_at'],
                'deadline' => $data['deadline'] ?? null, 'priority' => $data['priority'], 'instructions' => $data['instructions'] ?? null,
                'overlap_authorized' => $request->boolean('authorize_overlap')]);
            return count($records);
        }, 3);
        return back()->with('success', "{$created} survey task(s) assigned. They will appear in the surveyor's mobile assignments after sync.");
    }

    public function reassignSurvey(Request $request, SurveyorAssignment $assignment, AuditService $audit)
    {
        $data = $request->validate(['surveyor_id' => ['required', 'integer', 'exists:users,id'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $team = $this->teams($request->user())->whereKey($assignment->survey_team_id)->firstOrFail();
        if (! $team->members()->whereKey($data['surveyor_id'])->exists()) {
            throw ValidationException::withMessages(['surveyor_id' => 'Choose a new surveyor who belongs to this survey team.']);
        }
        $nextUser = User::findOrFail($data['surveyor_id']);
        if (! $nextUser->isActive() || ! $nextUser->hasAnyRole([UserRole::Surveyor->value, UserRole::SurveyTeamLeader->value])) {
            throw ValidationException::withMessages(['surveyor_id' => 'Choose an active surveyor in this team.']);
        }
        DB::transaction(function () use ($request, $assignment, $data, $audit) {
            $current = SurveyorAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'assigned') throw ValidationException::withMessages(['assignment' => 'Only pending assignments can be reassigned. Started or completed work must retain its owner.']);
            $old = $current->toArray();
            $current->update(['status' => 'reassigned']);
            $replacement = SurveyorAssignment::create(['assignment_uuid' => (string) Str::uuid(), 'survey_team_id' => $current->survey_team_id,
                'feeder_id' => $current->feeder_id, 'surveyor_id' => $data['surveyor_id'], 'assigned_by' => $request->user()->id,
                'assigned_at' => today(), 'deadline' => $current->deadline, 'priority' => $current->priority, 'instructions' => $current->instructions,
                'status' => 'assigned']);
            $audit->record($request->user(), 'survey.task_reassigned', $replacement, $old, $replacement->toArray(), $data['reason']);
        });
        return back()->with('success', 'Pending survey task reassigned.');
    }

    public function returnSurvey(Request $request, SurveyorAssignment $assignment, AuditService $audit)
    {
        $data = $request->validate(['comments' => ['required', 'string', 'min:3', 'max:2000']]);
        $this->teams($request->user())->whereKey($assignment->survey_team_id)->firstOrFail();
        DB::transaction(function () use ($request, $assignment, $data, $audit) {
            $task = SurveyorAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->status === 'submitted', 422, 'Only submitted survey work can be returned for correction.');
            $survey = FieldSurvey::where('collected_by', $task->surveyor_id)->where('survey_team_id', $task->survey_team_id)
                ->where('feeder_id', $task->feeder_id)->where('status', 'submitted')->latest('updated_at')->lockForUpdate()->firstOrFail();
            $old = $task->toArray();
            $task->update(['status' => 'returned', 'return_comments' => $data['comments'], 'completed_at' => null]);
            $audit->record($request->user(), 'survey.task_returned', $task, $old, $task->fresh()->toArray(), $data['comments']);
            $audit->record($request->user(), 'field_survey.returned_for_correction', $survey, $survey->toArray(), ['status' => $survey->status, 'comments' => $data['comments']], $data['comments']);
        });
        return back()->with('success', 'Survey returned to the assigned surveyor with correction comments.');
    }

    public function workflowIndex(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['super_admin', 'data_entry_verification_team_leader', 'mdb_creation_team_leader']), 403);
        $batches = SurveyBatch::with(['project', 'feeder', 'surveyTeam', 'entryAssignee', 'verificationAssignee', 'mdbAssignee'])
            ->when(! $user->hasRole('super_admin'), function ($query) use ($user) {
                $query->where(function ($scope) use ($user) {
                    if ($user->hasRole('data_entry_verification_team_leader')) {
                        $scope->whereIn('survey_team_id', SurveyTeam::whereHas('members', fn ($m) => $m->where('users.id', $user->id)->where('survey_team_members.is_leader', true))->select('id'));
                    }
                    if ($user->hasRole('mdb_creation_team_leader')) {
                        $scope->orWhereIn('project_id', $user->mdbTeams()->where('status', 'active')->select('mdb_teams.project_id'));
                    }
                });
            })->latest()->paginate(30);
        $users = User::where('status', 'active')->orderBy('name')->get();
        return view('team-work.workflow', compact('batches', 'users'));
    }

    public function assignWorkflow(Request $request, AuditService $audit)
    {
        $data = $request->validate(['batch_id' => ['required', 'integer', 'exists:mdb_workflow_batches,id'],
            'stage' => ['required', 'in:entry,verification,mdb'], 'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:1000']]);
        $actor = $request->user();
        $stage = $data['stage'];
        abort_unless($actor->hasRole('super_admin') || ($stage === 'mdb' ? $actor->hasRole('mdb_creation_team_leader') : $actor->hasRole('data_entry_verification_team_leader')), 403);
        $batch = SurveyBatch::with('surveyTeam')->findOrFail($data['batch_id']);
        if (! $actor->hasRole('super_admin')) {
            if ($stage === 'mdb') abort_unless($actor->mdbTeams()->where('status', 'active')->where('project_id', $batch->project_id)->exists(), 403);
            else abort_unless($batch->surveyTeam?->members()->whereKey($actor->id)->where('survey_team_members.is_leader', true)->exists(), 403);
        }
        $target = User::findOrFail($data['user_id']);
        abort_unless($target->isActive(), 422, 'Select an active user.');
        $field = match ($stage) { 'entry' => 'entry_assigned_to', 'verification' => 'verification_assigned_to', 'mdb' => 'mdb_assigned_to' };
        $role = match ($stage) { 'entry' => 'survey_data_entry_operator', 'verification' => 'survey_data_verifier', 'mdb' => 'mdb_generator' };
        abort_unless($target->hasAnyRole([$role, 'super_admin']), 422, 'The selected user does not have the role required for this stage.');
        if (! $actor->hasRole('super_admin') && ! $target->hasRole('super_admin')) {
            if (in_array($stage, ['entry', 'verification'], true)) {
                abort_unless($batch->surveyTeam?->members()->whereKey($target->id)->exists(), 422, 'The operator must belong to this batch survey team.');
            } else {
                abort_unless($target->mdbTeams()->where('status', 'active')->where('project_id', $batch->project_id)->exists(), 422, 'The MDB creator must belong to an active MDB team for this project.');
            }
        }
        if ($stage === 'entry') abort_unless(in_array($batch->survey_status, ['entry', 'returned'], true), 422, 'Entry work can be assigned only while a batch is in entry or returned status.');
        if ($stage === 'verification') abort_unless(in_array($batch->survey_status, ['submitted', 'awaiting_verification'], true), 422, 'Verification work can be assigned only after submission.');
        if ($stage === 'mdb') abort_unless($batch->survey_status === 'approved', 422, 'MDB work can be assigned only after verification is approved.');
        if ($stage === 'verification' && ((int) $batch->entry_operator_id === (int) $target->id || (int) $batch->entry_assigned_to === (int) $target->id || in_array($target->id, $batch->entry_actor_ids ?? [], true))) {
            throw ValidationException::withMessages(['user_id' => 'The person who entered this batch cannot verify their own work.']);
        }
        DB::transaction(function () use ($request, $batch, $field, $target, $audit, $data, $stage) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $old = $locked->only([$field, 'survey_status']);
            $locked->update([$field => $target->id]);
            $audit->record($request->user(), 'mdb.workflow_task_assigned', $locked, $old, $locked->fresh()->only([$field, 'survey_status']) + ['stage' => $stage, 'assignee' => $target->name], $data['reason'] ?? null);
        });
        return back()->with('success', 'Workflow task assigned to '.$target->name.'.');
    }

    private function teams(User $user)
    {
        $query = SurveyTeam::query()->where('status', 'active')->whereHas('project', fn ($q) => $q->where('status', 'active'));
        if (! $user->hasRole(UserRole::SuperAdmin->value)) {
            $query->whereHas('members', fn ($q) => $q->where('users.id', $user->id)->where('survey_team_members.is_leader', true));
        }
        return $query;
    }
}
