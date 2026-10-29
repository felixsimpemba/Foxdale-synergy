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
 * To incident managers when an RCA is sent for review.
 */
class RcaReadyForReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Rca $rca, public Incident $incident, public User $submittedBy)
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
            ->subject("RCA ready for review: {$this->incident->ref}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->submittedBy->name} sent the root cause analysis of {$this->incident->ref} \"{$this->incident->title}\" for review.")
            ->action('Review the RCA', RcaLinks::incident($this->incident))
            ->line('Approve it, or reject it with a reason so the author can fix it.');
    }
}
