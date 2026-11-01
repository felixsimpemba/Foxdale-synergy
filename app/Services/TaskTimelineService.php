<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The merged activity feed of a task. Comments appear once per thread, at the time the thread
 * started, with their replies (oldest first) inside. The shape stays {type, at, actor, data}
 * like IncidentTimelineService.
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

        $threads = $task->comments()
            ->withTrashed()
            ->whereNull('parent_id')
            ->with([
                'user', 'mentions',
                'replies' => fn ($query) => $query->withTrashed()->with('user', 'mentions'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TaskComment $comment): array => $this->threadItem($comment));

        return $activities
            ->concat($threads)
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
    private function threadItem(TaskComment $comment): array
    {
        $lastReply = $comment->replies->last();

        return [
            'type' => 'comment',
            'at' => $comment->created_at->toIso8601ZuluString(),
            'actor' => $this->person($comment),
            'data' => [
                ...$this->commentData($comment),
                'replies' => $comment->replies->map(fn (TaskComment $reply): array => [
                    ...$this->commentData($reply),
                    'at' => $reply->created_at?->toIso8601ZuluString(),
                    'actor' => $this->person($reply),
                ])->values()->all(),
                'last_activity_at' => ($lastReply === null ? $comment->created_at : $lastReply->created_at)?->toIso8601ZuluString(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commentData(TaskComment $comment): array
    {
        $deleted = $comment->trashed();

        return [
            'id' => $comment->id,
            'parent_id' => $comment->parent_id,
            'body' => $deleted ? null : $comment->body,
            'is_deleted' => $deleted,
            'edited_at' => $comment->edited_at?->toIso8601ZuluString(),
            'mentions' => $deleted
                ? []
                : $comment->mentions->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(TaskComment $comment): ?array
    {
        return $comment->trashed() || $comment->user === null ? null : [
            'id' => $comment->user->id,
            'name' => $comment->user->name,
        ];
    }
}
