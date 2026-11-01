<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\TaskApproved;
use App\Notifications\TaskNotice;
use App\Services\Notifications\NotificationService;

/**
 * Tell the assignee and reporter that the task passed review.
 */
class NotifyOnTaskApproved
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TaskApproved $event): void
    {
        $task = $event->task->loadMissing(['assignee', 'reporter', 'project']);
        $url = config('incidents.frontend_url')."/projects/{$task->project_id}/tasks/{$task->id}";
        $title = "Approved: {$task->ref}";

        $this->notifications->send(
            [$task->assignee, $task->reporter],
            NotificationEvent::TaskApproved,
            $task,
            ['title' => $title, 'body' => $task->title, 'url' => $url],
            new TaskNotice($title, [
                "{$task->ref} {$task->title}",
                "Project: {$task->project->name}",
                $event->actor === null ? 'It was approved.' : "{$event->actor->name} approved it.",
            ], $url),
            $event->actor,
        );
    }
}
