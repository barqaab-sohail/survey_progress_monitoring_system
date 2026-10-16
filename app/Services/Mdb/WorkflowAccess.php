<?php

namespace App\Services\Mdb;

use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WorkflowAccess
{
    public const ABILITIES = ['view', 'upload', 'edit', 'submit', 'verify', 'configure', 'analyze', 'export', 'surveyVerify', 'process', 'generate', 'prepare'];

    public const SURVEY_ROLE_ABILITIES = [
        'survey_data_entry_operator' => ['view', 'upload', 'edit', 'submit'],
        'survey_data_verifier' => ['view', 'surveyVerify'],
        'mdb_generator' => ['view', 'prepare', 'export', 'generate'],
    ];

    public function allows(User $user, string $ability): bool
    {
        if (! $user->isActive() || ($user->organization_id && $user->organization?->status->value !== 'active')) {
            return false;
        }
        if ($user->hasRole('super_admin')) {
            return true;
        }
        if ($ability === 'configure') {
            return false;
        }
        // Supplemental roles or direct grants cannot expand a dedicated survey role.
        // Multiple dedicated roles retain only their shared abilities.
        foreach (self::SURVEY_ROLE_ABILITIES as $role => $abilities) {
            if ($user->hasRole($role) && ! in_array($ability, $abilities, true)) {
                return false;
            }
        }

        return $user->checkPermissionTo('MdbWorkflow:'.ucfirst($ability));
    }

    public function canEdit(User $user, SurveyBatch $batch): bool
    {
        return $this->allowsForBatch($user, $batch, 'edit') && (! $batch->staged_workflow || in_array($batch->survey_status, ['entry', 'returned'], true));
    }

    public function canUpload(User $user, SurveyBatch $batch): bool
    {
        return $this->allowsForBatch($user, $batch, 'upload') && (! $batch->staged_workflow || in_array($batch->survey_status, ['entry', 'returned'], true));
    }

    public function allowsForBatch(User $user, SurveyBatch $batch, string $ability): bool
    {
        if (! $this->allows($user, $ability)) {
            return false;
        }
        if (! $batch->staged_workflow || $user->hasRole('super_admin')) {
            return true;
        }
        $role = match ($ability) {
            'edit', 'upload', 'submit' => 'survey_data_entry_operator',
            'surveyVerify' => 'survey_data_verifier',
            'generate', 'export', 'prepare' => 'mdb_generator',
            'verify', 'process' => 'super_admin',
            default => null,
        };

        return $role === null || $user->hasRole($role);
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
        abort_unless($this->allowsForBatch($user, $batch, $ability) && $this->visible($user)->whereKey($batch->id)->exists(), 403);
        if ($batch->staged_workflow) {
            if (in_array($ability, ['edit', 'upload', 'submit'], true)) {
                abort_unless(in_array($batch->survey_status, ['entry', 'returned'], true), 403, 'Survey entry is locked.');
                if (in_array($ability, ['edit', 'submit'], true)) {
                    $this->assertSourcesReady($batch);
                }
            }
            if ($ability === 'surveyVerify') {
                abort_if(in_array($user->id, $batch->entry_actor_ids ?? [], true), 403, 'Entry contributors cannot verify their survey.');
            }
            if (in_array($ability, ['process', 'verify', 'export', 'generate', 'prepare'], true)) {
                abort_unless($batch->survey_status === 'approved', 403, 'Survey approval is required before network processing.');
            }
        }
    }

    public function authorizeReviewChecks(User $user, SurveyBatch $batch): void
    {
        if ($this->canEdit($user, $batch)) {
            $this->authorize($user, $batch, 'edit');

            return;
        }

        $this->authorizeCorrection($user, $batch);
    }

    public function authorizeCorrection(User $user, SurveyBatch $batch): void
    {
        $this->authorize($user, $batch, 'surveyVerify');
        abort_unless($batch->staged_workflow ? $batch->survey_status === 'awaiting_verification' : in_array($batch->status, ['draft', 'submitted', 'returned', 'awaiting_verification'], true), 403,
            'Corrections and verification checks are available while the survey is pending verification.');
        $this->assertSourcesReady($batch);
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
