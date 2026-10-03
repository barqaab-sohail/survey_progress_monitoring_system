<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Circle;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CirclePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Circle');
    }

    public function view(AuthUser $authUser, Circle $circle): bool
    {
        return $authUser->can('View:Circle');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Circle');
    }

    public function update(AuthUser $authUser, Circle $circle): bool
    {
        return $authUser->can('Update:Circle');
    }

    public function delete(AuthUser $authUser, Circle $circle): bool
    {
        return $authUser->can('Delete:Circle');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Circle');
    }

    public function restore(AuthUser $authUser, Circle $circle): bool
    {
        return $authUser->can('Restore:Circle');
    }

    public function forceDelete(AuthUser $authUser, Circle $circle): bool
    {
        return $authUser->can('ForceDelete:Circle');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Circle');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Circle');
    }

    public function replicate(AuthUser $authUser, Circle $circle): bool
    {
        return $authUser->can('Replicate:Circle');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Circle');
    }
}
