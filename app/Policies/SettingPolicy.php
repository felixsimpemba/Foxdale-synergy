<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::IntegrationManage->value);
    }

    public function update(User $user): bool
    {
        return $user->can(PermissionKey::IntegrationManage->value);
    }
}
