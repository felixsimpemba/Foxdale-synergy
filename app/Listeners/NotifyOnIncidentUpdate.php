<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\UpdatePosted;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Str;

/**
 * A timeline update posted with "Send to chat" is pushed to the WhatsApp groups an admin
 * switched on. Individual recipients are not notified by this event; mentions and status
 * changes have their own notifications.
 */
class NotifyOnIncidentUpdate
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(UpdatePosted $event): void
    {
        if (! $event->notifyChannels) {
            return;
        }

        $update = $event->update->loadMissing(['incident', 'user']);
        $incident = $update->incident;

        if ($incident === null) {
            return;
        }

        $author = $update->user?->name ?? 'Someone';
        $body = Str::limit(trim((string) $update->body), 500);

        $this->notifications->send(
            [],
            NotificationEvent::IncidentUpdatePosted,
            $incident,
            [
                'title' => "Update on {$incident->ref}",
                'body' => $body === '' ? "*{$author}* posted an update." : "*{$author}*\n{$body}",
                'url' => config('incidents.frontend_url')."/incidents/{$incident->id}#update-{$update->id}",
            ],
        );
    }
}
