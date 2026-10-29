<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Service;
use App\Models\User;

/**
 * Any signed-in user can read the service catalog; only user managers can change it.
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Service $service): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can(PermissionKey::UserManage->value);
    }
}
