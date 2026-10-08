<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\SurveyTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class FieldSurveyAccess
{
    public function teams(User $user): Builder
    {
        return SurveyTeam::query()->where('status', 'active')
            ->whereHas('project', fn (Builder $query) => $query->where('status', 'active'))
            ->unless($user->hasRole(UserRole::SuperAdmin->value), fn (Builder $query) => $query
                ->whereHas('members', fn (Builder $members) => $members->where('users.id', $user->id)));
    }

    public function activeAssignments(Builder $query): void
    {
        $query->where('status', 'active')
            ->whereDate('start_date', '<=', today())
            ->where(fn (Builder $dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
    }

    public function feeders(User $user): Builder
    {
        $teamIds = $this->teams($user)->select('survey_teams.id');

        return Feeder::active()->whereHas('project', fn (Builder $query) => $query->where('status', 'active'))
            ->whereHas('assignments', function (Builder $query) use ($teamIds): void {
                $this->activeAssignments($query);
                $query->whereIn('survey_team_id', $teamIds)->whereHas('surveyTeam', fn (Builder $team) => $team
                    ->whereColumn('survey_teams.project_id', 'feeders.project_id'));
            });
    }

    public function authorizeCollection(User $user, int $teamId, int $feederId): array
    {
        abort_unless($user->isActive() && (! $user->organization_id || $user->organization?->status->value === 'active'), 403, 'Your account or organization is inactive.');
        abort_unless($user->hasAnyRole([UserRole::SuperAdmin->value, UserRole::SurveyTeamLeader->value]), 403);
        $team = $this->teams($user)->find($teamId);
        $feeder = Feeder::active()->find($feederId);
        abort_unless($team && $feeder && $team->project_id === $feeder->project_id, 403, 'Your survey team or feeder is unavailable. Download assignments again.');
        $assignment = $feeder->assignments()->where('survey_team_id', $team->id);
        $this->activeAssignments($assignment->getQuery());
        abort_unless($assignment->exists(), 403, 'This feeder is no longer assigned to your survey team.');

        return [$team, $feeder];
    }

    public function visible(User $user): Builder
    {
        abort_unless($user->isActive() && (! $user->organization_id || $user->organization?->status->value === 'active'), 403, 'Your account or organization is inactive.');
        $query = FieldSurvey::query();
        if ($user->hasAnyRole([UserRole::SuperAdmin->value, UserRole::ProjectManager->value, UserRole::ManagementViewer->value])) {
            return $query;
        }
        abort_unless($user->hasRole(UserRole::SurveyTeamLeader->value), 403);

        return $query->where('collected_by', $user->id)
            ->whereIn('survey_team_id', $this->teams($user)->select('survey_teams.id'))
            ->whereHas('feeder', function (Builder $feeder): void {
                $feeder->where('status', 'active')->whereHas('assignments', function (Builder $assignment): void {
                    $this->activeAssignments($assignment);
                    $assignment->whereColumn('feeder_assignments.survey_team_id', 'field_surveys.survey_team_id');
                });
            });
    }

    public function authorizeOwner(User $user, FieldSurvey $survey): void
    {
        abort_unless($survey->collected_by === $user->id, 403, 'This field survey belongs to another account.');
        $this->authorizeCollection($user, $survey->survey_team_id, $survey->feeder_id);
    }
}
