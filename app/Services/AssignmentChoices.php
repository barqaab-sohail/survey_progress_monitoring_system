<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\Mdb\SurveyBatch;
use App\Models\MdbTeam;
use App\Models\SurveyorAssignment;
use App\Models\SurveyTeam;
use App\Models\User;

/** Paginated choices for the Admin hub; existing handlers validate every write again. */
class AssignmentChoices
{
    public function get(User $user, string $type, array $filters): array
    {
        if (! app(UserAssignmentManager::class)->eligible($user, $type)) {
            return ['items' => [], 'more' => false];
        }
        $search = trim($filters['task_search'] ?? '');
        $project = $filters['task_project'] ?? null;
        $page = max(1, (int) ($filters['choice_page'] ?? 1));
        if (isset(AssignmentOverview::FIELDS[$type])) {
            $field = AssignmentOverview::FIELDS[$type];
            $relation = ['entry' => 'entryAssignee', 'verification' => 'verificationAssignee', 'mdb' => 'mdbAssignee'][$type];
            $statuses = ['entry' => ['entry', 'returned'], 'verification' => ['submitted', 'awaiting_verification'], 'mdb' => ['approved']][$type];
            $query = SurveyBatch::with(['feeder', 'project', $relation])->whereIn('survey_status', $statuses)
                ->when($project, fn ($q) => $q->where('project_id', $project))
                ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                    $q->where('id', $search)->orWhereHas('feeder', fn ($f) => $f->where('feeder_code', 'like', "%{$search}%")->orWhere('feeder_name', 'like', "%{$search}%"));
                }));
            $rows = $query->orderByDesc('id')->paginate(30, ['*'], 'choice_page', $page);
            $items = $rows->map(function ($batch) use ($type, $field, $relation, $user) {
                $own = (int) $batch->$field === $user->id;
                $self = $type === 'verification' && ((int) $batch->entry_operator_id === $user->id || (int) $batch->entry_assigned_to === $user->id || in_array($user->id, $batch->entry_actor_ids ?? [], true));

                return ['id' => $batch->id, 'label' => 'Batch #'.$batch->id.' · '.$batch->feeder?->feeder_code,
                    'detail' => $batch->project?->name.' · '.str_replace('_', ' ', $batch->survey_status).' · Current owner: '.($batch->$relation?->name ?? 'Unassigned'),
                    'owner' => (int) $batch->$field, 'disabled' => $own || $self,
                    'note' => $own ? 'Already assigned to this user' : ($self ? 'Cannot verify own data entry' : '')];
            });
        } elseif (in_array($type, ['survey_team', 'mdb_team'], true)) {
            $model = $type === 'survey_team' ? SurveyTeam::class : MdbTeam::class;
            $rows = $model::with('project')->withExists(['members as already_member' => fn ($q) => $q->where('users.id', $user->id)])
                ->where('status', 'active')->whereHas('project', fn ($q) => $q->where('status', 'active'))
                ->when($project, fn ($q) => $q->where('project_id', $project))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
                ->orderBy('name')->paginate(30, ['*'], 'choice_page', $page);
            $items = $rows->map(fn ($team) => ['id' => $team->id, 'label' => $team->code.' · '.$team->name,
                'detail' => $team->project?->name, 'owner' => 0, 'disabled' => (bool) $team->already_member, 'note' => $team->already_member ? 'Already a member' : '']);
        } else {
            $query = Feeder::with('project')->where('status', 'active')->whereHas('project', fn ($q) => $q->where('status', 'active'));
            $team = $user->surveyTeams()->where('survey_teams.id', $filters['survey_team_id'] ?? 0)->where('status', 'active')->first();
            if (! $team) {
                return ['items' => [], 'more' => false];
            }
            $query->where('project_id', $team->project_id);
            $rows = $query->when($project, fn ($q) => $q->where('project_id', $project))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('feeder_code', 'like', "%{$search}%")->orWhere('feeder_name', 'like', "%{$search}%")))
                ->orderBy('feeder_code')->paginate(30, ['*'], 'choice_page', $page);
            $open = $type === 'mobile' ? SurveyorAssignment::with('surveyor')->whereIn('feeder_id', $rows->pluck('id'))->whereIn('status', UserAssignmentManager::OPEN_SURVEY)->get()->groupBy('feeder_id') : collect();
            $shared = $type === 'feeder_pool' ? $team->feederAssignments()->where('status', 'active')->pluck('feeder_id')->all() : [];
            $items = $rows->map(function ($feeder) use ($user, $open, $shared) {
                $work = $open->get($feeder->id, collect());
                $own = $work->contains('surveyor_id', $user->id) || in_array($feeder->id, $shared);

                return ['id' => $feeder->id, 'label' => $feeder->feeder_code.' · '.$feeder->feeder_name,
                    'detail' => $feeder->project?->name.($work->isNotEmpty() ? ' · Open survey: '.$work->pluck('surveyor.name')->implode(', ') : ''),
                    'owner' => 0, 'disabled' => $own, 'note' => $own ? 'Already allocated' : ''];
            });
        }

        return ['items' => $items->values()->all(), 'more' => $rows->hasMorePages(), 'page' => $page];
    }
}
