<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SurveyDailyEntry;
use App\Models\User;

class SurveyDailyEntryPolicy
{
    public function update(User $user, SurveyDailyEntry $entry): bool
    {
        return $this->create($user)
            && ($entry->entered_by === $user->id || $user->hasRole(UserRole::SuperAdmin->value))
            && $entry->canBeEdited();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::SurveyTeamLeader->value, UserRole::SuperAdmin->value]);
    }
}
