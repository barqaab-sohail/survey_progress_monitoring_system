<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GridStation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GridStationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GridStation');
    }

    public function view(AuthUser $authUser, GridStation $gridStation): bool
    {
        return $authUser->can('View:GridStation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GridStation');
    }

    public function update(AuthUser $authUser, GridStation $gridStation): bool
    {
        return $authUser->can('Update:GridStation');
    }

    public function delete(AuthUser $authUser, GridStation $gridStation): bool
    {
        return $authUser->can('Delete:GridStation');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:GridStation');
    }

    public function restore(AuthUser $authUser, GridStation $gridStation): bool
    {
        return $authUser->can('Restore:GridStation');
    }

    public function forceDelete(AuthUser $authUser, GridStation $gridStation): bool
    {
        return $authUser->can('ForceDelete:GridStation');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GridStation');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GridStation');
    }

    public function replicate(AuthUser $authUser, GridStation $gridStation): bool
    {
        return $authUser->can('Replicate:GridStation');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GridStation');
    }
}
