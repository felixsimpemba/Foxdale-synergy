<?php

namespace App\Http\Resources;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes the key or signing secret; those are returned once by the controller.
 *
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'source' => $this->source,
            'key_prefix' => $this->key_prefix,
            'scopes' => $this->scopes,
            'ip_allowlist' => $this->ip_allowlist,
            'has_signing_secret' => $this->signing_secret !== null,
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
