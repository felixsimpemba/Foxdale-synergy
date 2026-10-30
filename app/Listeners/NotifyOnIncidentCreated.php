<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\IncidentCreated;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\Recipients;

/**
 * A new incident reaches the team lead, and incident managers too for SEV1 and SEV2. The
 * assignee is told separately by the assignment notification.
 */
class NotifyOnIncidentCreated
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident->loadMissing(['team.lead', 'service', 'reporter']);

        $recipients = collect([Recipients::teamLead($incident)]);

        if ($incident->severity->isHigh()) {
            $recipients = $recipients->merge(Recipients::incidentManagers());
        }

        $recipients = $recipients->filter()->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        $title = "New {$incident->severity->label()} incident: {$incident->ref}";
        $url = config('incidents.frontend_url')."/incidents/{$incident->id}";

        $this->notifications->send(
            $recipients,
            NotificationEvent::IncidentCreated,
            $incident,
            ['title' => $title, 'body' => $incident->title, 'url' => $url],
            new IncidentNotice($title, [
                $incident->title,
                $incident->service === null ? 'No service' : "Service: {$incident->service->name}",
                $incident->reporter === null ? 'Reported through an integration' : "Reported by {$incident->reporter->name}",
            ], $url),
            $incident->reporter,
        );
    }
}
