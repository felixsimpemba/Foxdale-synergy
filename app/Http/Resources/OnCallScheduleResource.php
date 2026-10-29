<?php

namespace App\Http\Resources;

use App\Models\OnCallSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnCallSchedule
 */
class OnCallScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'team' => $this->whenLoaded('team', fn () => ['id' => $this->team->id, 'name' => $this->team->name]),
            'name' => $this->name,
            'rotation_type' => $this->rotation_type,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'shifts' => OnCallShiftResource::collection($this->whenLoaded('shifts')),
        ];
    }
}
