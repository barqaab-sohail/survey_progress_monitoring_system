<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HtDataImport;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class HtDataImportPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:HtDataImport');
    }

    public function view(AuthUser $authUser, HtDataImport $htDataImport): bool
    {
        return $authUser->can('View:HtDataImport');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:HtDataImport');
    }

    public function update(AuthUser $authUser, HtDataImport $htDataImport): bool
    {
        return $authUser->can('Update:HtDataImport');
    }

    public function delete(AuthUser $authUser, HtDataImport $htDataImport): bool
    {
        return $authUser->can('Delete:HtDataImport');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:HtDataImport');
    }

    public function restore(AuthUser $authUser, HtDataImport $htDataImport): bool
    {
        return $authUser->can('Restore:HtDataImport');
    }

    public function forceDelete(AuthUser $authUser, HtDataImport $htDataImport): bool
    {
        return $authUser->can('ForceDelete:HtDataImport');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:HtDataImport');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:HtDataImport');
    }

    public function replicate(AuthUser $authUser, HtDataImport $htDataImport): bool
    {
        return $authUser->can('Replicate:HtDataImport');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:HtDataImport');
    }
}
