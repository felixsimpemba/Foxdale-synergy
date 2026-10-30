<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'color' => $this->color,
            'review_required' => $this->review_required,
            'owner_team_id' => $this->owner_team_id,
            'lead_id' => $this->lead_id,
            'owner_team' => $this->whenLoaded('ownerTeam', fn () => $this->ownerTeam === null ? null : [
                'id' => $this->ownerTeam->id,
                'name' => $this->ownerTeam->name,
            ]),
            'lead' => $this->whenLoaded('lead', fn () => $this->lead === null ? null : [
                'id' => $this->lead->id,
                'name' => $this->lead->name,
            ]),
            'members_count' => $this->whenCounted('members'),
            'tasks_count' => $this->whenCounted('tasks'),
            'open_tasks_count' => $this->whenCounted('open_tasks'),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role->value,
                'role_label' => $user->pivot->role->label(),
            ])),
            'active_sprint' => $this->whenLoaded(
                'activeSprint',
                fn () => $this->activeSprint === null ? null : new SprintResource($this->activeSprint),
            ),
            'sprints' => SprintResource::collection($this->whenLoaded('sprints')),
            'archived_at' => $this->archived_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
