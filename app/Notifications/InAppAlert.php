<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use Illuminate\Notifications\Notification;

/**
 * An entry in the in-app bell. Stored in the notifications table with everything the bell
 * needs to render it and link to the incident.
 */
class InAppAlert extends Notification
{
    /**
     * @param  array{title: string, body: string, url: string}  $content
     */
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly array $content,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => $this->event->value,
            'category' => $this->event->category(),
            'title' => $this->content['title'],
            'body' => $this->content['body'],
            'url' => $this->content['url'],
        ];
    }
}
