<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function create(User $actor): bool
    {
        return $actor->hasRole('system_admin');
    }

    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('system_admin');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasRole('system_admin');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasRole('system_admin');
    }

    public function delete(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) return false;
        return $actor->hasRole('system_admin');
    }
}
