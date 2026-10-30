<?php

namespace App\Http\Resources;

use App\Models\StatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StatusHistory
 */
class StatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'note' => $this->note,
            'changed_by' => $this->whenLoaded('user', fn () => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name]),
            'changed_at' => $this->changed_at->toIso8601ZuluString(),
            'seconds_in_previous' => $this->seconds_in_previous,
        ];
    }
}
