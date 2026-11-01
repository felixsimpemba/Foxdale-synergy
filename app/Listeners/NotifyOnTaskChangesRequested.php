<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\TaskChangesRequested;
use App\Notifications\TaskNotice;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Str;

/**
 * Tell the assignee and reporter that a reviewer sent the task back.
 */
class NotifyOnTaskChangesRequested
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(TaskChangesRequested $event): void
    {
        $task = $event->task->loadMissing(['assignee', 'reporter', 'project']);
        $url = config('incidents.frontend_url')."/projects/{$task->project_id}/tasks/{$task->id}";
        $title = "Changes requested on {$task->ref}";
        $note = Str::limit(trim($event->note), 400);
        $lines = [
            "{$task->ref} {$task->title}",
            "Project: {$task->project->name}",
            $event->actor === null ? 'Changes were requested.' : "{$event->actor->name} asked for changes.",
        ];

        if ($note !== '') {
            $lines[] = $note;
        }

        $this->notifications->send(
            [$task->assignee, $task->reporter],
            NotificationEvent::TaskChangesRequested,
            $task,
            ['title' => $title, 'body' => Str::limit(trim($event->note), 160), 'url' => $url],
            new TaskNotice($title, $lines, $url),
            $event->actor,
        );
    }
}
