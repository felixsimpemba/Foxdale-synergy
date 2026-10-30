<?php

namespace App\Http\Resources;

use App\Models\RcaActionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RcaActionItem
 */
class RcaActionItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rca_id' => $this->rca_id,
            'incident' => $this->when(
                $this->relationLoaded('rca') && $this->rca->relationLoaded('incident'),
                fn () => [
                    'id' => $this->rca->incident->id,
                    'ref' => $this->rca->incident->ref,
                    'title' => $this->rca->incident->title,
                    'severity' => $this->rca->incident->severity->value,
                ],
            ),
            'type' => $this->type,
            'title' => $this->title,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner === null ? null : ['id' => $this->owner->id, 'name' => $this->owner->name]),
            'owner_id' => $this->owner_id,
            'due_date' => $this->due_date?->toDateString(),
            'priority' => $this->priority,
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'note' => $this->note,
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'completed_by' => $this->whenLoaded('completer', fn () => $this->completer === null ? null : ['id' => $this->completer->id, 'name' => $this->completer->name]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
