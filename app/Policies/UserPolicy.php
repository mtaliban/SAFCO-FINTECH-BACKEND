<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Governs admin access to the user management endpoints.
 * Routes are already guarded by `role:system_admin` middleware,
 * so all policy methods simply confirm the admin role is present.
 */
class UserPolicy
{
    use HandlesAuthorization;

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
        // Admins cannot delete themselves (prevents lockout)
        if ($actor->id === $target->id) return false;
        return $actor->hasRole('system_admin');
    }
}
