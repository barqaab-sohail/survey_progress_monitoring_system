<?php

namespace App\Policies;

use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use App\Services\Mdb\WorkflowAccess;

class SurveyBatchPolicy
{
    public function view(User $user, SurveyBatch $batch): bool
    {
        return app(WorkflowAccess::class)->allows($user, 'view') && app(WorkflowAccess::class)->visible($user)->whereKey($batch->id)->exists();
    }

    public function update(User $user, SurveyBatch $batch): bool
    {
        return app(WorkflowAccess::class)->allows($user, 'edit') && $this->view($user, $batch);
    }
}
