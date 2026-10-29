<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Daily reminder for a SEV1 or SEV2 incident whose RCA is past its due date and not yet
 * approved. Sent to the RCA author (or the incident owner when no RCA was started) and to
 * incident managers.
 */
class RcaDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident, public Carbon $dueAt, public string $state)
    {
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
        $due = $this->dueAt->copy()->setTimezone($notifiable->timezone ?: 'UTC')->format('j M Y');

        return (new MailMessage)
            ->subject("RCA overdue: {$this->incident->ref}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The root cause analysis of {$this->incident->ref} \"{$this->incident->title}\" was due on {$due} and is {$this->state}.")
            ->line("{$this->incident->severity->label()} incidents cannot be closed until their RCA is approved.")
            ->action('Open the RCA', RcaLinks::incident($this->incident));
    }
}
