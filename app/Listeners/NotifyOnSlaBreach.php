<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\SlaBreached;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\Recipients;

/**
 * Tell the assignee, the team lead and incident managers when an SLA target is missed.
 * Critical: this one ignores quiet hours.
 */
class NotifyOnSlaBreach
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SlaBreached $event): void
    {
        $incident = $event->incident->loadMissing(['assignee', 'service', 'team.lead']);
        $url = config('incidents.frontend_url')."/incidents/{$incident->id}";
        $title = "SLA breached: {$incident->ref} {$event->metric->value} target";
        $line = "{$incident->ref} \"{$incident->title}\" missed its {$event->metric->value} target.";

        $recipients = collect([$incident->assignee, Recipients::teamLead($incident)])
            ->merge(Recipients::incidentManagers())
            ->filter()
            ->unique('id');

        $this->notifications->send(
            $recipients,
            NotificationEvent::SlaBreached,
            $incident,
            ['title' => $title, 'body' => $line, 'url' => $url],
            new IncidentNotice($title, [$line, "{$incident->severity->label()} · service: ".($incident->service->name ?? 'none')], $url),
        );
    }
}
