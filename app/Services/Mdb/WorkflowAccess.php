<?php

namespace App\Services\Mdb;

use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WorkflowAccess
{
    public const ABILITIES = ['view', 'upload', 'edit', 'verify', 'configure', 'analyze', 'export', 'surveyVerify', 'process', 'generate'];

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
        if ($batch->staged_workflow) {
            if (in_array($ability, ['edit', 'upload'], true)) {
                abort_unless(in_array($batch->survey_status, ['entry', 'returned'], true), 403, 'Survey entry is locked.');
                if ($ability === 'edit') {
                    $this->assertSourcesReady($batch);
                }
            }
            if ($ability === 'surveyVerify') {
                abort_if(in_array($user->id, $batch->entry_actor_ids ?? [], true), 403, 'Entry contributors cannot verify their survey.');
            }
            if (in_array($ability, ['process', 'verify', 'export', 'generate'], true)) {
                abort_unless($batch->survey_status === 'approved', 403, 'Survey approval is required before network processing.');
            }
        }
        abort_unless($this->allows($user, $ability) && $this->visible($user)->whereKey($batch->id)->exists(), 403);
    }

    public function assertSourcesReady(SurveyBatch $batch): void
    {
        foreach (['pdf', 'gpx'] as $kind) {
            abort_unless($batch->sources()->where('kind', $kind)->where('status', 'ready')->exists(), 422, 'Both PDF and GPX must finish processing before entry.');
        }
        abort_if($batch->sources()->where('status', '!=', 'ready')->exists(), 422, 'Source processing is incomplete.');
    }

    public function recordEntry(SurveyBatch $batch, User $actor): void
    {
        if ($batch->staged_workflow) {
            $batch->update(['entry_actor_ids' => array_values(array_unique([...($batch->entry_actor_ids ?? []), $actor->id])), 'entry_operator_id' => $actor->id]);
        }
    }
}
