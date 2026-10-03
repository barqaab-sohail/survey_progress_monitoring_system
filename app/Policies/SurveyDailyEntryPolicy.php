<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class SurveyDailyEntryPolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::SurveyTeamLeader->value, UserRole::SuperAdmin->value]);
    }
}
