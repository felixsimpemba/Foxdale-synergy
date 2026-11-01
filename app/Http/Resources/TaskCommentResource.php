<?php

namespace App\Http\Resources;

use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskComment
 */
class TaskCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'parent_id' => $this->parent_id,
            'body' => $this->trashed() ? null : $this->body,
            'is_deleted' => $this->trashed(),
            'edited_at' => $this->edited_at?->toIso8601ZuluString(),
            'author' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'mentions' => $this->whenLoaded('mentions', fn () => $this->mentions->map(fn ($person): array => [
                'id' => $person->id,
                'name' => $person->name,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
