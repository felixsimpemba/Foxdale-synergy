<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The plain email for incident events (assigned, status change, SLA warning or breach).
 * Richer events, such as an RCA ready for review, keep their own classes.
 */
class IncidentNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly array $lines,
        public readonly string $url,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subjectLine)
            ->greeting("Hello {$notifiable->name},");

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message->action('Open the incident', $this->url);
    }
}
