<?php

namespace App\Policies;

use App\Models\Transformer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TransformerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:Transformer');
    }

    public function view(AuthUser $user, Transformer $record): bool
    {
        return $user->can('View:Transformer');
    }

    public function create(AuthUser $user): bool
    {
        return false;
    }

    public function update(AuthUser $user, Transformer $record): bool
    {
        return false;
    }

    public function delete(AuthUser $user, Transformer $record): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $user): bool
    {
        return false;
    }

    public function restore(AuthUser $user, Transformer $record): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $user): bool
    {
        return false;
    }

    public function forceDelete(AuthUser $user, Transformer $record): bool
    {
        return false;
    }

    public function forceDeleteAny(AuthUser $user): bool
    {
        return false;
    }

    public function replicate(AuthUser $user, Transformer $record): bool
    {
        return false;
    }

    public function reorder(AuthUser $user): bool
    {
        return false;
    }
}
