<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Runbook;
use App\Models\User;

/**
 * Everyone can read runbooks; people who write fix documentation maintain them; incident
 * managers and admins delete them.
 */
class RunbookPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Runbook $runbook): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::ResolutionWrite->value);
    }

    public function update(User $user, Runbook $runbook): bool
    {
        return $user->can(PermissionKey::ResolutionWrite->value);
    }

    public function delete(User $user, Runbook $runbook): bool
    {
        return $user->can(PermissionKey::DocumentDelete->value);
    }
}
