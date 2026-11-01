<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\TaskAssigned;
use App\Notifications\TaskNotice;
use App\Services\Notifications\NotificationService;

/**
 * Tell the new assignee, unless they assigned it to themselves.
 */
class NotifyOnTaskAssigned
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TaskAssigned $event): void
    {
        $task = $event->task->loadMissing(['assignee', 'project']);
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $event->previousAssignee?->id) {
            return;
        }

        $url = config('incidents.frontend_url')."/projects/{$task->project_id}/tasks/{$task->id}";
        $title = "Assigned to you: {$task->ref}";

        $this->notifications->send(
            [$assignee],
            NotificationEvent::TaskAssigned,
            $task,
            ['title' => $title, 'body' => $task->title, 'url' => $url],
            new TaskNotice($title, [
                "{$task->ref} {$task->title}",
                "Project: {$task->project->name}",
                $event->actor === null ? 'It was assigned to you.' : "It was assigned to you by {$event->actor->name}.",
            ], $url),
            $event->actor,
        );
    }
}
