<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;

/**
 * Any signed-in user can read categories; only user managers can add them.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }
}
