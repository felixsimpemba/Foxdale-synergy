<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\TaskCommentPosted;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\TaskNotice;
use App\Services\Mentions\TaskMentionNotifier;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A comment goes to the people involved in the task; a reply goes to the person replied to.
 * People mentioned in the text are told separately and never get both messages.
 */
class NotifyOnTaskCommentPosted
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TaskMentionNotifier $mentions,
    ) {}

    public function handle(TaskCommentPosted $event): void
    {
        $comment = $event->comment->loadMissing(['user', 'mentions', 'task.project']);

        if ($comment->trashed()) {
            return;
        }

        $task = $comment->task;
        $author = $event->actor ?? $comment->user;

        if ($task === null || $author === null) {
            return;
        }

        $this->mentions->notify($task, $comment, $author, $comment->mentions, (string) $comment->body);

        $recipients = $this->recipients($task, $comment)
            ->reject(fn (?User $person): bool => $person === null
                || $person->id === $author->id
                || $comment->mentions->contains('id', $person->id))
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $isReply = $comment->parent_id !== null;
        $title = $isReply
            ? "{$author->name} replied on {$task->ref}"
            : "New comment on {$task->ref}";
        $url = config('incidents.frontend_url')."/projects/{$task->project_id}/tasks/{$task->id}#comment-{$comment->id}";
        $body = Str::limit(trim((string) $comment->body), 160);

        $this->notifications->send(
            $recipients,
            $isReply ? NotificationEvent::TaskReply : NotificationEvent::TaskComment,
            $task,
            ['title' => $title, 'body' => $body, 'url' => $url],
            new TaskNotice($title, [
                "{$author->name} ".($isReply ? 'replied' : 'commented')." on {$task->ref} \"{$task->title}\".",
                Str::limit(trim((string) $comment->body), 400),
            ], $url),
            $author,
        );
    }

    /**
     * Top-level comments reach the assignee and reporter; replies reach the thread author.
     *
     * @return Collection<int, User|null>
     */
    private function recipients(Task $task, TaskComment $comment): Collection
    {
        if ($comment->parent_id !== null) {
            return collect([TaskComment::query()->withTrashed()->whereKey($comment->parent_id)->first()?->user]);
        }

        $task->loadMissing(['assignee', 'reporter']);

        return collect([$task->assignee, $task->reporter]);
    }
}
