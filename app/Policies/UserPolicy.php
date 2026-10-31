<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function assignRoles(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    /**
     * Activate or deactivate. Nobody can deactivate themselves.
     */
    public function changeStatus(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value) && $user->isNot($model);
    }

    /**
     * Archive (soft delete). Nobody can archive themselves.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value) && $user->isNot($model);
    }

    public function restore(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    /**
     * Admins send reset links to other people; their own password is changed from My account.
     */
    public function sendPasswordReset(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value) && $user->isNot($model);
    }

    public function revokeSessions(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value) && $user->isNot($model);
    }

    public function clearLockout(User $user, User $model): bool
    {
        return $user->can(PermissionKey::UserManage->value) && $user->isNot($model);
    }
}
