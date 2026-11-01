<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\TaskReviewRequested;
use App\Notifications\TaskNotice;
use App\Services\Notifications\NotificationService;

/**
 * Tell the reviewer that a task is waiting for their decision.
 */
class NotifyOnTaskReviewRequested
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TaskReviewRequested $event): void
    {
        $task = $event->task->loadMissing('project');
        $url = config('incidents.frontend_url')."/projects/{$task->project_id}/tasks/{$task->id}";
        $title = "Review requested: {$task->ref}";

        $this->notifications->send(
            [$event->reviewer],
            NotificationEvent::TaskReviewRequested,
            $task,
            ['title' => $title, 'body' => $task->title, 'url' => $url],
            new TaskNotice($title, [
                "{$task->ref} {$task->title}",
                "Project: {$task->project->name}",
                $event->actor === null ? 'It is waiting for your review.' : "{$event->actor->name} asked you to review it.",
            ], $url),
            $event->actor,
        );
    }
}
