<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\ApiKey;
use App\Models\User;

class ApiKeyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::IntegrationManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::IntegrationManage->value);
    }

    public function rotate(User $user, ApiKey $apiKey): bool
    {
        return $user->can(PermissionKey::IntegrationManage->value) && ! $apiKey->isRevoked();
    }

    public function delete(User $user, ApiKey $apiKey): bool
    {
        return $user->can(PermissionKey::IntegrationManage->value);
    }
}
