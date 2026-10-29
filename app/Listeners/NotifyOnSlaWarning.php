<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\SlaWarningRaised;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\Recipients;

/**
 * Warn the assignee and the team lead at 80% of an SLA target, so someone can step in.
 */
class NotifyOnSlaWarning
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SlaWarningRaised $event): void
    {
        $incident = $event->incident->loadMissing(['assignee', 'service', 'team.lead']);
        $url = config('incidents.frontend_url')."/incidents/{$incident->id}";
        $title = "SLA warning: {$incident->ref} {$event->metric->value} target";
        $line = "The {$event->metric->value} target for {$incident->ref} \"{$incident->title}\" is 80% used.";

        $this->notifications->send(
            [$incident->assignee, Recipients::teamLead($incident)],
            NotificationEvent::SlaWarning,
            $incident,
            ['title' => $title, 'body' => $line, 'url' => $url],
            new IncidentNotice($title, [$line, "{$incident->severity->label()} · service: ".($incident->service->name ?? 'none')], $url),
        );
    }
}
