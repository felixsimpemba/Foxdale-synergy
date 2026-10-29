<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\IncidentAssigned;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationService;

/**
 * Tell the new assignee, unless they assigned it to themselves.
 */
class NotifyOnIncidentAssigned
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(IncidentAssigned $event): void
    {
        if ($event->incident->assignee_id === null || $event->incident->assignee_id === $event->previousAssignee?->id) {
            return;
        }

        $incident = $event->incident->loadMissing(['assignee', 'service']);
        $url = config('incidents.frontend_url')."/incidents/{$incident->id}";
        $title = "Assigned to you: {$incident->ref}";

        $this->notifications->send(
            [$incident->assignee],
            NotificationEvent::IncidentAssigned,
            $incident,
            ['title' => $title, 'body' => $incident->title, 'url' => $url],
            new IncidentNotice($title, [
                "{$incident->severity->label()} {$incident->title}",
                $incident->service === null ? 'No service' : "Service: {$incident->service->name}",
                $event->actor === null ? 'It was assigned to you.' : "It was assigned to you by {$event->actor->name}.",
            ], $url),
            $event->actor,
        );
    }
}
