<?php

namespace App\Http\Resources;

use App\Models\RoutingRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoutingRule
 */
class RoutingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'priority' => $this->priority,
            'category_id' => $this->category_id,
            'service_id' => $this->service_id,
            'min_severity' => $this->min_severity,
            'max_severity' => $this->max_severity,
            'team_id' => $this->team_id,
            'routes_to_service_owner' => $this->team_id === null,
            'escalation_policy_id' => $this->escalation_policy_id,
            'assignment_mode' => $this->assignment_mode,
            'notify_channel_ids' => $this->notify_channel_ids ?? [],
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
