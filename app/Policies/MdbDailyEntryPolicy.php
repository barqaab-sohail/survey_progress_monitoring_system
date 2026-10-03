<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class MdbDailyEntryPolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::MdbTeamUser->value, UserRole::SuperAdmin->value]);
    }
}
