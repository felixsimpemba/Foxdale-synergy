<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use Illuminate\Support\Collection;

/**
 * The merged activity feed of a task. Comments join this feed from their own table when the
 * collaboration slice lands; the shape stays {type, at, actor, data} like IncidentTimelineService.
 */
class TaskTimelineService
{
    /**
     * @return Collection<int, array{type: string, at: string, actor: array{id: int, name: string}|null, data: array<string, mixed>}>
     */
    public function timeline(Task $task): Collection
    {
        $task->load('activities.user');

        return $task->activities
            ->map(fn (TaskActivity $activity): array => [
                'type' => $activity->type->value,
                'at' => $activity->created_at?->toIso8601ZuluString() ?? '',
                'actor' => $activity->user === null ? null : [
                    'id' => $activity->user->id,
                    'name' => $activity->user->name,
                ],
                'data' => [
                    'from' => $activity->from_value,
                    'to' => $activity->to_value,
                    ...($activity->meta ?? []),
                ],
            ])
            ->sortBy('at')
            ->values();
    }
}
