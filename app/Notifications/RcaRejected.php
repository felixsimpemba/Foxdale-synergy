<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Models\Rca;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the author when a reviewer sends an RCA back, with the reason.
 */
class RcaRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Rca $rca, public Incident $incident, public User $reviewer)
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
        return (new MailMessage)
            ->subject("RCA returned: {$this->incident->ref}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->reviewer->name} returned your root cause analysis of {$this->incident->ref} \"{$this->incident->title}\".")
            ->line('Reason: '.$this->rca->rejection_reason)
            ->action('Update the RCA', RcaLinks::incident($this->incident))
            ->line('Make the changes and send it for review again.');
    }
}
