<?php

namespace App\Policies;

use App\Enums\AssignmentStatus;
use App\Enums\UserRole;
use App\Models\MdbProcessingAssignment;
use App\Models\User;

class MdbProcessingAssignmentPolicy
{
    public function create(User $user): bool
    {
        return $user->hasAnyRole([UserRole::SuperAdmin->value, UserRole::ProjectManager->value]);
    }

    public function view(User $user, MdbProcessingAssignment $assignment): bool
    {
        return $user->hasAnyRole([UserRole::SuperAdmin->value, UserRole::ProjectManager->value, UserRole::ManagementViewer->value])
            || ($user->hasRole(UserRole::MdbProcessingUser->value) && $user->organization_id === $assignment->organization_id);
    }

    public function recordProgress(User $user, MdbProcessingAssignment $assignment): bool
    {
        return $assignment->status === AssignmentStatus::Active
            && (($user->hasRole(UserRole::MdbProcessingUser->value) && $user->organization_id === $assignment->organization_id)
                || $user->hasRole(UserRole::SuperAdmin->value));
    }
}
