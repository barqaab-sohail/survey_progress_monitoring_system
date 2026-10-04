<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MdbDailyEntry;
use App\Models\User;

class MdbDailyEntryPolicy
{
    public function update(User $user, MdbDailyEntry $entry): bool
    {
        return $this->create($user)
            && ((int) $entry->entered_by === (int) $user->id || $user->hasRole(UserRole::SuperAdmin->value))
            && $entry->canBeEdited();
    }

    public function create(User $user): bool
    {
        return $user->isActive()
            && $user->hasAnyRole([UserRole::MdbTeamUser->value, UserRole::SuperAdmin->value]);
    }
}
