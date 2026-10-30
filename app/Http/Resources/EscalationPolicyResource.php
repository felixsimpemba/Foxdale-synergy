<?php

namespace App\Http\Resources;

use App\Models\EscalationLevel;
use App\Models\EscalationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EscalationPolicy
 */
class EscalationPolicyResource extends JsonResource
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
            'levels' => $this->whenLoaded('levels', fn () => $this->levels->map(fn (EscalationLevel $level): array => [
                'level' => $level->level,
                'after_minutes' => $level->after_minutes,
                'target_type' => $level->target_type,
                'target_id' => $level->target_id,
                'max_severity' => $level->max_severity,
            ])),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
