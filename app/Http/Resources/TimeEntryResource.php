<?php

namespace App\Http\Resources;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TimeEntry
 */
class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incident_id,
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'user_id' => $this->user_id,
            'minutes' => $this->minutes,
            'note' => $this->note,
            'worked_on' => $this->worked_on->toDateString(),
            'source' => $this->source,
            'is_running' => $this->isRunning(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'ended_at' => $this->ended_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
