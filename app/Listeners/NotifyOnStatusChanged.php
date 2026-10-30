<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\IncidentStatusChanged;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\Recipients;

/**
 * Tell the reporter, the assignee and the watchers when an incident changes status.
 */
class NotifyOnStatusChanged
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(IncidentStatusChanged $event): void
    {
        $incident = $event->incident;
        $url = config('incidents.frontend_url')."/incidents/{$incident->id}";
        $title = "{$incident->ref} is now {$event->to->value}";

        $this->notifications->send(
            Recipients::involvedIn($incident),
            NotificationEvent::IncidentStatusChanged,
            $incident,
            ['title' => $title, 'body' => $incident->title, 'url' => $url],
            new IncidentNotice($title, [
                "{$incident->title} moved from {$event->from->value} to {$event->to->value}.",
                $event->actor === null ? 'It was changed automatically.' : "Changed by {$event->actor->name}.",
            ], $url),
            $event->actor,
        );
    }
}
