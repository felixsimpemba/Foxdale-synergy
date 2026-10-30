<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\RoutingRule;
use App\Models\User;

class RoutingRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function update(User $user, RoutingRule $routingRule): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function delete(User $user, RoutingRule $routingRule): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    /**
     * Run the routing simulator (POST /routing-rules/test).
     */
    public function simulate(User $user): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }
}
