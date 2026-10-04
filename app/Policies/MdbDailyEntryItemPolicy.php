<?php

namespace App\Policies;

use App\Enums\OrganizationType;
use App\Enums\RecordStatus;
use App\Enums\SurveyItemStatus;
use App\Enums\UserRole;
use App\Models\MdbDailyEntryItem;
use App\Models\User;

class MdbDailyEntryItemPolicy
{
    public function verifyAny(User $user): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasRole(UserRole::SuperAdmin->value)) {
            return true;
        }

        return $user->hasRole(UserRole::MdbProcessingUser->value)
            && $user->organization?->type === OrganizationType::ThirdParty
            && $user->organization?->status === RecordStatus::Active;
    }

    public function returnAny(User $user): bool
    {
        return $this->verifyAny($user);
    }

    public function verify(User $user, MdbDailyEntryItem $item): bool
    {
        return $this->verifyAny($user)
            && $item->status === SurveyItemStatus::Submitted
            && (int) $item->entry->entered_by !== (int) $user->id;
    }

    public function return(User $user, MdbDailyEntryItem $item): bool
    {
        return $this->verify($user, $item);
    }

    public function update(User $user, MdbDailyEntryItem $item): bool
    {
        return $user->isActive()
            && $item->status === SurveyItemStatus::Returned
            && ($user->hasRole(UserRole::SuperAdmin->value)
                || ($user->hasRole(UserRole::MdbTeamUser->value)
                    && (int) $item->entry->entered_by === (int) $user->id));
    }
}
