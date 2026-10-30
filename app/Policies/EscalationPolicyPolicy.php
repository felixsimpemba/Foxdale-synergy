<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\EscalationPolicy;
use App\Models\User;

class EscalationPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function view(User $user, EscalationPolicy $escalationPolicy): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function update(User $user, EscalationPolicy $escalationPolicy): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function delete(User $user, EscalationPolicy $escalationPolicy): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }
}
