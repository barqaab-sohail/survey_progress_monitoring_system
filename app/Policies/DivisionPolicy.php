<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Division;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DivisionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Division');
    }

    public function view(AuthUser $authUser, Division $division): bool
    {
        return $authUser->can('View:Division');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Division');
    }

    public function update(AuthUser $authUser, Division $division): bool
    {
        return $authUser->can('Update:Division');
    }

    public function delete(AuthUser $authUser, Division $division): bool
    {
        return $authUser->can('Delete:Division');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Division');
    }

    public function restore(AuthUser $authUser, Division $division): bool
    {
        return $authUser->can('Restore:Division');
    }

    public function forceDelete(AuthUser $authUser, Division $division): bool
    {
        return $authUser->can('ForceDelete:Division');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Division');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Division');
    }

    public function replicate(AuthUser $authUser, Division $division): bool
    {
        return $authUser->can('Replicate:Division');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Division');
    }
}
