<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
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

        $activities = $task->activities
            ->map(fn (TaskActivity $activity): array => $this->activityItem($activity));

        $comments = $task->comments()
            ->withTrashed()
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TaskComment $comment): array => $this->commentItem($comment));

        return $activities
            ->concat($comments)
            ->sortBy('at')
            ->values();
    }

    /**
     * @return array{type: string, at: string, actor: array{id: int, name: string}|null, data: array<string, mixed>}
     */
    private function activityItem(TaskActivity $activity): array
    {
        return [
            'type' => $activity->type->value,
            'at' => $activity->created_at->toIso8601ZuluString(),
            'actor' => $activity->user === null ? null : [
                'id' => $activity->user->id,
                'name' => $activity->user->name,
            ],
            'data' => array_merge([
                'from' => $activity->from_value,
                'to' => $activity->to_value,
            ], $activity->meta ?? []),
        ];
    }

    /**
     * @return array{type: string, at: string, actor: array{id: int, name: string}|null, data: array<string, mixed>}
     */
    private function commentItem(TaskComment $comment): array
    {
        return [
            'type' => 'comment',
            'at' => $comment->created_at->toIso8601ZuluString(),
            'actor' => $comment->trashed() || $comment->user === null ? null : [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
            ],
            'data' => [
                'id' => $comment->id,
                'body' => $comment->trashed() ? null : $comment->body,
                'is_deleted' => $comment->trashed(),
                'edited_at' => $comment->edited_at?->toIso8601ZuluString(),
            ],
        ];
    }
}
