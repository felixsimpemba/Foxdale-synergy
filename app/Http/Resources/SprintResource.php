<?php

namespace App\Http\Resources;

use App\Models\Sprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sprint
 */
class SprintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'goal' => $this->goal,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'tasks_count' => $this->whenCounted('tasks'),
            'done_tasks_count' => $this->whenCounted('done_tasks'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
