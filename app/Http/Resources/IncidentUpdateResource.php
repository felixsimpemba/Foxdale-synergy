<?php

namespace App\Http\Resources;

use App\Models\IncidentUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A deleted update only appears as the head of a thread that still has replies; its body,
 * author and files are left out.
 *
 * @mixin IncidentUpdate
 */
class IncidentUpdateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deleted = $this->deleted_at !== null;

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'type' => $this->type,
            'body' => $deleted ? null : $this->body,
            'visibility' => $this->visibility,
            'author' => $deleted ? null : $this->whenLoaded('user', fn () => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name]),
            'attachments' => $deleted ? [] : AttachmentResource::collection($this->whenLoaded('attachments')),
            'mentions' => $deleted ? [] : $this->whenLoaded('mentions', fn () => $this->mentions->map(fn ($person): array => ['id' => $person->id, 'name' => $person->name])),
            'replies' => self::collection($this->whenLoaded('replies')),
            'is_deleted' => $deleted,
            'edited_at' => $this->edited_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
