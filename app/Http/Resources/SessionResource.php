<?php

namespace App\Http\Resources;

use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin PersonalAccessToken
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $this->id,
            'device' => str($this->name)->after(TokenService::SESSION_PREFIX)->toString() ?: 'Unknown device',
            'is_current' => $current instanceof PersonalAccessToken && $current->getKey() === $this->id,
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
