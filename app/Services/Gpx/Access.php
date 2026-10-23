<?php

namespace App\Services\Gpx;

use App\Models\Feeder;
use App\Models\GpxCalculation;
use App\Models\Project;
use App\Models\User;
use App\Services\FieldSurveyAccess;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class Access
{
    public function allows(User $user): bool
    {
        return $user->isActive() && (! $user->organization_id || $user->organization?->status->value === 'active')
            && ($user->hasAnyRole(['super_admin', 'project_manager', 'survey_team_leader', 'surveyor'])
                || app(WorkflowAccess::class)->allows($user, 'upload'));
    }

    public function global(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'project_manager']);
    }

    public function projects(User $user): Builder
    {
        return Project::where('status', 'active')->unless($this->global($user), fn (Builder $q) => $q
            ->whereIn('id', app(FieldSurveyAccess::class)->teams($user)->select('survey_teams.project_id')));
    }

    public function feeders(User $user): Builder
    {
        return $this->global($user)
            ? Feeder::active()->whereIn('project_id', $this->projects($user)->select('id'))
            : app(FieldSurveyAccess::class)->feeders($user);
    }

    public function visible(User $user): Builder
    {
        Gate::authorize('gpx-calculator.use');

        return GpxCalculation::query()->unless($this->global($user), fn (Builder $q) => $q->where('created_by', $user->id)
            ->where(fn (Builder $projects) => $projects->whereNull('project_id')->orWhereIn('project_id', $this->projects($user)->select('id')))
            ->where(fn (Builder $feeders) => $feeders->whereNull('feeder_id')->orWhereIn('feeder_id', $this->feeders($user)->select('feeders.id'))));
    }
}
