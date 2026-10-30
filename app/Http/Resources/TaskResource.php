<?php

namespace App\Http\Resources;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Positions are integers within a board column; the board sorts by position then id.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'number' => $this->number,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'key' => $this->project->key,
                'name' => $this->project->name,
            ]),
            'sprint_id' => $this->sprint_id,
            'sprint' => $this->whenLoaded('sprint', fn () => $this->sprint === null ? null : [
                'id' => $this->sprint->id,
                'name' => $this->sprint->name,
                'state' => $this->sprint->state->value,
            ]),
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'ref' => $this->parent->ref,
                'title' => $this->parent->title,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'assignee_id' => $this->assignee_id,
            'reporter_id' => $this->reporter_id,
            'reviewer_id' => $this->reviewer_id,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->summary($this->assignee)),
            'reporter' => $this->whenLoaded('reporter', fn () => $this->summary($this->reporter)),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->summary($this->reviewer)),
            'review_state' => $this->review_state->value,
            'review_state_label' => $this->review_state->label(),
            'story_points' => $this->story_points,
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->due_date !== null
                && ! $this->status->isFinished()
                && $this->due_date->isBefore(today()),
            'is_blocked' => $this->is_blocked,
            'blocked_reason' => $this->blocked_reason,
            'position' => $this->position,
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'subtasks' => TaskResource::collection($this->whenLoaded('subtasks')),
            'subtasks_count' => $this->whenCounted('subtasks'),
            'done_subtasks_count' => $this->whenCounted('done_subtasks'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function summary(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
