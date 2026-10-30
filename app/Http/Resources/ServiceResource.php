<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
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
            'owner_team_id' => $this->owner_team_id,
            'owner_team' => $this->whenLoaded('ownerTeam', fn () => $this->ownerTeam === null ? null : ['id' => $this->ownerTeam->id, 'name' => $this->ownerTeam->name]),
            'criticality' => $this->criticality,
            'runbook_url' => $this->runbook_url,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
