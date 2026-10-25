<?php

namespace App\Services;

use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\TeamWorkAssignmentController;
use App\Models\Feeder;
use App\Models\Mdb\SurveyBatch;
use App\Models\MdbTeam;
use App\Models\SurveyorAssignment;
use App\Models\SurveyTeam;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Additive Admin adapter: existing handlers retain ownership of authorization and assignment logic. */
class UserAssignmentManager
{
    public const OPEN_SURVEY = ['assigned', 'in_progress', 'draft', 'returned', 'submitted'];

    public const TASKS = [
        'mobile' => 'Mobile App Survey', 'entry' => 'Data Entry', 'verification' => 'Data Verification',
        'mdb' => 'MDB Creation', 'survey_team' => 'Survey Team Membership', 'mdb_team' => 'MDB Team Membership',
        'feeder_pool' => 'Shared Survey Feeders',
    ];

    public function eligible(User $user, string $type): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return match ($type) {
            'mobile' => $user->hasAnyRole(['surveyor', 'survey_team_leader', 'super_admin']),
            'entry' => $user->hasAnyRole(['survey_data_entry_operator', 'super_admin']),
            'verification' => $user->hasAnyRole(['survey_data_verifier', 'super_admin']),
            'mdb' => $user->hasAnyRole(['mdb_generator', 'super_admin']),
            'survey_team' => $user->hasAnyRole(['surveyor', 'survey_team_leader', 'data_entry_verification_team_leader', 'survey_data_entry_operator', 'survey_data_verifier', 'super_admin']),
            'mdb_team' => $user->hasAnyRole(['mdb_generator', 'mdb_creation_team_leader', 'mdb_team_user', 'super_admin']),
            'feeder_pool' => $user->surveyTeams()->exists(),
            default => false,
        };
    }

    private function forward(Request $request, array $data): Request
    {
        $copy = $request->duplicate(null, $data);
        $copy->setUserResolver(fn () => $request->user());

        return $copy;
    }

    public function assign(Request $request): string
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id', 'task_type' => 'required|in:'.implode(',', [...array_keys(self::TASKS), 'mobile_reassign']),
            'confirmed' => 'required|accepted', 'task_ids' => 'required_unless:task_type,mobile_reassign|array|min:1|max:50',
            'task_ids.*' => 'integer|distinct|min:1', 'expected_assignees' => 'sometimes|array',
            'expected_assignees.*' => 'nullable|integer|min:0', 'replace_existing' => 'sometimes|boolean',
            'reason' => 'nullable|string|max:2000',
        ]);
        try {
            return DB::transaction(function () use ($request, $data) {
                $target = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
                $kind = $data['task_type'];
                if ($kind !== 'mobile_reassign' && ! $this->eligible($target, $kind)) {
                    throw ValidationException::withMessages(['user_id' => 'This user is inactive or does not have the existing role required for this task type.']);
                }
                $ids = array_map('intval', $data['task_ids'] ?? []);
                sort($ids);
                $audit = app(AuditService::class);
                if (isset(AssignmentOverview::FIELDS[$kind])) {
                    $field = AssignmentOverview::FIELDS[$kind];
                    $batches = SurveyBatch::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                    if ($batches->count() !== count($ids)) {
                        throw ValidationException::withMessages(['task_ids' => 'A selected batch no longer exists. Reload the task list.']);
                    }
                    $changed = $skipped = 0;
                    foreach ($batches as $batch) {
                        if (! array_key_exists($batch->id, $data['expected_assignees'] ?? []) || (int) $batch->$field !== (int) $data['expected_assignees'][$batch->id]) {
                            throw ValidationException::withMessages(['task_ids' => 'An assignment changed while you were reviewing it. Reload before assigning; no tasks were changed.']);
                        }
                        if ((int) $batch->$field === $target->id) {
                            $skipped++;

                            continue;
                        }
                        if ($batch->$field && (! $request->boolean('replace_existing') || mb_strlen(trim((string) $request->input('reason'))) < 3)) {
                            throw ValidationException::withMessages(['replace_existing' => 'A selected batch already has an owner. Confirm reassignment and provide a reason to replace that owner.']);
                        }
                        app(TeamWorkAssignmentController::class)->assignWorkflow($this->forward($request, [
                            'batch_id' => $batch->id, 'stage' => $kind, 'user_id' => $target->id, 'reason' => $request->input('reason'),
                        ]), $audit);
                        $changed++;
                    }

                    return "{$changed} task(s) assigned to {$target->name}; {$skipped} already assigned and left unchanged.";
                }
                if ($kind === 'mobile_reassign') {
                    $extra = $request->validate(['assignment_id' => 'required|integer|exists:surveyor_assignments,id', 'expected_owner' => 'required|integer', 'reason' => 'required|string|min:3|max:1000']);
                    $assignment = SurveyorAssignment::whereKey($extra['assignment_id'])->lockForUpdate()->firstOrFail();
                    if ($assignment->surveyor_id !== (int) $extra['expected_owner']) {
                        throw ValidationException::withMessages(['assignment_id' => 'This task owner changed. Reload before reassigning.']);
                    }
                    if ($assignment->surveyor_id === $target->id) {
                        return 'This task is already assigned to '.$target->name.'. No change was made.';
                    }
                    if (SurveyorAssignment::where('feeder_id', $assignment->feeder_id)->where('surveyor_id', $target->id)->whereIn('status', self::OPEN_SURVEY)->exists()) {
                        throw ValidationException::withMessages(['user_id' => 'This user already has open survey work on this feeder. No duplicate assignment was created.']);
                    }
                    app(TeamWorkAssignmentController::class)->reassignSurvey($this->forward($request, ['surveyor_id' => $target->id, 'reason' => $extra['reason']]), $assignment, $audit);

                    return 'Survey task reassigned to '.$target->name.'. The previous assignment and audit history were retained.';
                }
                if (in_array($kind, ['survey_team', 'mdb_team'], true)) {
                    $type = $kind === 'survey_team' ? 'survey' : 'mdb';
                    $model = $type === 'survey' ? SurveyTeam::class : MdbTeam::class;
                    $teams = $model::whereIn('id', $ids)->where('status', 'active')->whereHas('project', fn ($q) => $q->where('status', 'active'))->orderBy('id')->lockForUpdate()->get();
                    if ($teams->count() !== count($ids)) {
                        throw ValidationException::withMessages(['task_ids' => 'Choose active teams in active projects.']);
                    }
                    $changed = $skipped = 0;
                    foreach ($teams as $team) {
                        if ($team->members()->whereKey($target->id)->exists()) {
                            $skipped++;

                            continue;
                        }
                        // Adding membership never changes an existing member's leader flag.
                        app(TeamController::class)->member($this->forward($request, ['type' => $type, 'team_id' => $team->id, 'user_id' => $target->id, 'is_leader' => false]), $audit);
                        $changed++;
                    }

                    return "{$changed} team membership(s) added; {$skipped} existing membership(s) left unchanged.";
                }
                if (in_array($kind, ['mobile', 'feeder_pool'], true)) {
                    $extra = $request->validate(['survey_team_id' => 'required|integer|exists:survey_teams,id']);
                    $team = SurveyTeam::whereKey($extra['survey_team_id'])->lockForUpdate()->firstOrFail();
                    if (! $team->members()->whereKey($target->id)->exists()) {
                        throw ValidationException::withMessages(['survey_team_id' => 'The selected user must already belong to this survey team. Assign membership first.']);
                    }
                    $feeders = Feeder::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                    if ($feeders->count() !== count($ids)) {
                        throw ValidationException::withMessages(['task_ids' => 'A selected feeder no longer exists.']);
                    }
                    $changed = $skipped = 0;
                    if ($kind === 'mobile') {
                        $open = SurveyorAssignment::whereIn('feeder_id', $ids)->whereIn('status', self::OPEN_SURVEY)->lockForUpdate()->get();
                        $existing = $open->where('surveyor_id', $target->id)->pluck('feeder_id')->all();
                        $new = array_values(array_diff($ids, $existing));
                        if ($new && $open->whereIn('feeder_id', $new)->contains(fn ($a) => $a->surveyor_id !== $target->id) && ! $request->boolean('authorize_overlap')) {
                            throw ValidationException::withMessages(['authorize_overlap' => 'A feeder has open work assigned to another surveyor. Confirm overlapping work or use Reassign on the existing task.']);
                        }
                        if ($new) {
                            app(TeamWorkAssignmentController::class)->assignSurvey($this->forward($request, [
                                'survey_team_id' => $team->id, 'surveyor_id' => $target->id, 'feeder_ids' => $new,
                                'assigned_at' => $request->input('assigned_at'), 'deadline' => $request->input('deadline'),
                                'priority' => $request->input('priority', 'normal'), 'instructions' => $request->input('reason'),
                                ...($request->boolean('authorize_overlap') ? ['authorize_overlap' => 1] : []),
                            ]), $audit);
                        }
                        $changed = count($new);
                        $skipped = count($ids) - $changed;
                    } else {
                        foreach ($ids as $id) {
                            $before = $team->feederAssignments()->where('feeder_id', $id)->where('status', 'active')->exists();
                            app(TeamController::class)->assignFeeder($this->forward($request, [
                                'assignment_scope' => 'single', 'survey_team_id' => $team->id, 'feeder_id' => $id,
                                'start_date' => $request->input('assigned_at'), 'end_date' => $request->input('deadline'), 'remarks' => $request->input('reason'),
                            ]), $audit);
                            $before ? $skipped++ : $changed++;
                        }
                    }

                    return "{$changed} allocation(s) created; {$skipped} existing allocation(s) left unchanged.";
                }
                throw ValidationException::withMessages(['task_type' => 'Choose a supported assignment type.']);
            }, 3);
        } catch (\Throwable $exception) {
            // Delegated handlers flash success; do not retain that flash if a later bulk item rolls back.
            $request->session()->forget('success');
            if ($exception instanceof HttpException && $exception->getStatusCode() === 422) {
                throw ValidationException::withMessages(['task_ids' => $exception->getMessage()]);
            }
            throw $exception;
        }
    }
}
