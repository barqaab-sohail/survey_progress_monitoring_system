<?php

namespace App\Policies;

use App\Enums\SurveyItemStatus;
use App\Enums\UserRole;
use App\Models\SurveyDailyEntryItem;
use App\Models\User;

class SurveyDailyEntryItemPolicy
{
    public function update(User $user, SurveyDailyEntryItem $item): bool
    {
        return $item->status === SurveyItemStatus::Returned
            && ($user->hasRole(UserRole::SuperAdmin->value) || $item->entry->entered_by === $user->id);
    }

    public function verify(User $user, SurveyDailyEntryItem $item): bool
    {
        return $item->status === SurveyItemStatus::Submitted
            && $user->hasAnyRole([UserRole::MdbTeamUser->value, UserRole::SuperAdmin->value]);
    }

    public function return(User $user, SurveyDailyEntryItem $item): bool
    {
        return $this->verify($user, $item);
    }
}
