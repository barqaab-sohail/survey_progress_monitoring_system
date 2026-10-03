<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Feeder;
use App\Models\User;

class FeederPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'ViewAny:Feeder');
    }

    public function view(User $user, Feeder $feeder): bool
    {
        return $this->allowed($user, 'View:Feeder');
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'Create:Feeder');
    }

    public function update(User $user, Feeder $feeder): bool
    {
        return $this->allowed($user, 'Update:Feeder');
    }

    public function delete(User $user, Feeder $feeder): bool
    {
        return $this->allowed($user, 'Delete:Feeder');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allowed($user, 'DeleteAny:Feeder');
    }

    public function restore(User $user, Feeder $feeder): bool
    {
        return $this->allowed($user, 'Restore:Feeder');
    }

    public function restoreAny(User $user): bool
    {
        return $this->allowed($user, 'RestoreAny:Feeder');
    }

    public function forceDelete(User $user, Feeder $feeder): bool
    {
        return $this->allowed($user, 'ForceDelete:Feeder');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->allowed($user, 'ForceDeleteAny:Feeder');
    }

    public function replicate(User $user, Feeder $feeder): bool
    {
        return $this->allowed($user, 'Replicate:Feeder');
    }

    public function reorder(User $user): bool
    {
        return $this->allowed($user, 'Reorder:Feeder');
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole(UserRole::SuperAdmin->value) || $user->can($permission);
    }
}
