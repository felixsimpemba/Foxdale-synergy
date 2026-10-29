<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;

/**
 * SLA targets are part of the system settings managed by admins.
 */
class SlaPolicyPolicy
{
    public function update(User $user): bool
    {
        return $user->can(PermissionKey::IntegrationManage->value);
    }
}
