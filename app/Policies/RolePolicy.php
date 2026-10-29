<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }
}
