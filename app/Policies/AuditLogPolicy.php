<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::AuditView->value);
    }
}
