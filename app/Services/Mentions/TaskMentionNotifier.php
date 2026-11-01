<?php

namespace App\Services\Mentions;

use App\Enums\NotificationEvent;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\TaskNotice;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Tells people they were mentioned in a task comment, linking straight to it.
 */
class TaskMentionNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  Collection<int, User>  $people
     */
    public function notify(Task $task, TaskComment $comment, User $author, Collection $people, string $body): void
    {
        if ($people->isEmpty()) {
            return;
        }

        $title = "You were mentioned on {$task->ref}";
        $excerpt = Str::limit(trim($body), 160);
        $url = config('incidents.frontend_url')."/projects/{$task->project_id}/tasks/{$task->id}#comment-{$comment->id}";

        $this->notifications->send(
            $people,
            NotificationEvent::TaskMention,
            $task,
            ['title' => $title, 'body' => "{$author->name}: {$excerpt}", 'url' => $url],
            new TaskNotice($title, [
                "{$author->name} mentioned you on {$task->ref} \"{$task->title}\".",
                Str::limit(trim($body), 400),
            ], $url),
            $author,
        );
    }
}
