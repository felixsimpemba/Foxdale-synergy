<?php

namespace App\Http\Resources;

use App\Models\OnCallShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnCallShift
 */
class OnCallShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->on_call_schedule_id,
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'user_id' => $this->user_id,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'is_override' => $this->is_override,
        ];
    }
}
