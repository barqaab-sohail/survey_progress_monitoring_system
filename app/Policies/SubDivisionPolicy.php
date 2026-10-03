<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SubDivision;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SubDivisionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SubDivision');
    }

    public function view(AuthUser $authUser, SubDivision $subDivision): bool
    {
        return $authUser->can('View:SubDivision');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SubDivision');
    }

    public function update(AuthUser $authUser, SubDivision $subDivision): bool
    {
        return $authUser->can('Update:SubDivision');
    }

    public function delete(AuthUser $authUser, SubDivision $subDivision): bool
    {
        return $authUser->can('Delete:SubDivision');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SubDivision');
    }

    public function restore(AuthUser $authUser, SubDivision $subDivision): bool
    {
        return $authUser->can('Restore:SubDivision');
    }

    public function forceDelete(AuthUser $authUser, SubDivision $subDivision): bool
    {
        return $authUser->can('ForceDelete:SubDivision');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SubDivision');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SubDivision');
    }

    public function replicate(AuthUser $authUser, SubDivision $subDivision): bool
    {
        return $authUser->can('Replicate:SubDivision');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SubDivision');
    }
}
