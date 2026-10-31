<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'mattermost_username' => $this->mattermost_username,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'archived_at' => $this->deleted_at?->toIso8601ZuluString(),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'teams' => TeamResource::collection($this->whenLoaded('teams')),
            'is_lead' => $this->whenPivotLoaded('team_user', fn () => (bool) $this->resource->getRelation('pivot')->is_lead),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
