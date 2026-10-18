<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Feeder;
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
        $users = User::whereIn('id', $userIds)->where('status', 'active')->orderBy('name')->get();
        $assignments = SurveyorAssignment::with(['team.project', 'feeder', 'surveyor', 'assigner'])
            ->whereIn('survey_team_id', $teams->modelKeys())->latest()->paginate(40);
        $workload = SurveyorAssignment::selectRaw('surveyor_id, count(*) as assigned_count')
            ->where('status', 'assigned')->whereIn('surveyor_id', $userIds)->groupBy('surveyor_id')->pluck('assigned_count', 'surveyor_id');
        return view('team-work.assignments', compact('teams', 'assignments', 'workload', 'feeders', 'users'));
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
        abort_unless($team->members()->whereKey($data['surveyor_id'])->exists(), 422, 'The surveyor must belong to your survey team.');
        $surveyor = User::findOrFail($data['surveyor_id']);
        abort_unless($surveyor->isActive() && $surveyor->hasAnyRole([UserRole::Surveyor->value, UserRole::SurveyTeamLeader->value, UserRole::SuperAdmin->value]), 422, 'Select an active surveyor or a team leader authorized to conduct surveys.');

        $created = DB::transaction(function () use ($request, $data, $team, $audit) {
            $lockedTeam = SurveyTeam::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $feeders = Feeder::active()->where('project_id', $lockedTeam->project_id)->whereIn('id', $data['feeder_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($feeders->count() !== count($data['feeder_ids'])) throw ValidationException::withMessages(['feeder_ids' => 'All selected feeders must be active and belong to the selected project.']);
            $records = [];
            foreach ($feeders as $feeder) {
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
        abort_unless($team->members()->whereKey($data['surveyor_id'])->exists(), 422, 'The new surveyor must belong to your survey team.');
        $nextUser = User::findOrFail($data['surveyor_id']);
        abort_unless($nextUser->isActive() && $nextUser->hasAnyRole([UserRole::Surveyor->value, UserRole::SurveyTeamLeader->value]), 422);
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

    private function teams(User $user)
    {
        $query = SurveyTeam::query()->where('status', 'active')->whereHas('project', fn ($q) => $q->where('status', 'active'));
        if (! $user->hasRole(UserRole::SuperAdmin->value)) {
            $query->whereHas('members', fn ($q) => $q->where('users.id', $user->id)->where('survey_team_members.is_leader', true));
        }
        return $query;
    }
}
