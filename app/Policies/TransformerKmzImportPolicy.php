<?php

namespace App\Policies;

use App\Models\TransformerKmzImport;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TransformerKmzImportPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:TransformerKmzImport');
    }

    public function view(AuthUser $user, TransformerKmzImport $record): bool
    {
        return $user->can('View:TransformerKmzImport');
    }

    public function create(AuthUser $user): bool
    {
        return $user->can('Create:TransformerKmzImport');
    }

    public function update(AuthUser $user, TransformerKmzImport $record): bool
    {
        return false;
    }

    public function delete(AuthUser $user, TransformerKmzImport $record): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $user): bool
    {
        return false;
    }

    public function restore(AuthUser $user, TransformerKmzImport $record): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $user): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $user, TransformerKmzImport $record): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $user): bool
    {
        return false;
    }

    public function replicate(AuthUser $user, TransformerKmzImport $record): bool
    {
        return false;
    }

    public function reorder(AuthUser $user): bool
    {
        return false;
    }
}
