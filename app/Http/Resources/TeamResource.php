<?php

namespace App\Http\Resources;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead === null ? null : ['id' => $this->lead->id, 'name' => $this->lead->name]),
            'lead_user_id' => $this->lead_user_id,
            'members_count' => $this->whenCounted('members'),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'is_lead' => $this->whenPivotLoaded('team_user', fn () => (bool) $this->resource->getRelation('pivot')->is_lead),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
