<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Team;
use App\Models\User;

/**
 * Any signed-in user can read teams (the reporting form needs them); only user managers
 * can change them.
 */
class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Team $team): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }
}
