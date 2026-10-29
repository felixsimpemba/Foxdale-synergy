<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\OnCallSchedule;
use App\Models\User;

/**
 * Everyone can see rotations; incident managers and admins (routing.manage) change them.
 */
class OnCallSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, OnCallSchedule $onCallSchedule): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function update(User $user, OnCallSchedule $onCallSchedule): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function delete(User $user, OnCallSchedule $onCallSchedule): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }

    public function manageShifts(User $user, OnCallSchedule $onCallSchedule): bool
    {
        return $user->can(PermissionKey::RoutingManage->value);
    }
}
