<?php

namespace App\Services\Mdb;

use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WorkflowAccess
{
    public const ABILITIES = ['view', 'upload', 'edit', 'verify', 'configure', 'analyze', 'export'];

    public function allows(User $user, string $ability): bool
    {
        return $user->isActive() && (! $user->organization_id || $user->organization?->status->value === 'active')
            && ($user->hasRole('super_admin') || $user->checkPermissionTo('MdbWorkflow:'.ucfirst($ability)));
    }

    public function visible(User $user): Builder
    {
        $query = SurveyBatch::query();
        if ($user->hasAnyRole(['super_admin', 'project_manager'])) {
            return $query;
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->whereHas('surveyTeam.members', fn (Builder $members) => $members->where('users.id', $user->id))
                ->orWhereIn('project_id', $user->mdbTeams()->where('status', 'active')->select('mdb_teams.project_id'))
                ->orWhereIn('project_id', $user->processingTeams()->where('status', 'active')->select('processing_teams.project_id'));
        });
    }

    public function authorize(User $user, SurveyBatch $batch, string $ability = 'view'): void
    {
        abort_unless($this->allows($user, $ability) && $this->visible($user)->whereKey($batch->id)->exists(), 403);
    }
}
