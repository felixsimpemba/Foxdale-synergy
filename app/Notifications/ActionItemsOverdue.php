<?php

namespace App\Notifications;

use App\Models\RcaActionItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Daily reminder to an owner listing their overdue action items, or the weekly digest of
 * everyone's overdue items for incident managers.
 */
class ActionItemsOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, RcaActionItem>  $items  with rca.incident and owner loaded
     */
    public function __construct(public Collection $items, public bool $digest = false)
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
        $count = $this->items->count();
        $noun = $count === 1 ? 'action item' : 'action items';

        $message = (new MailMessage)
            ->subject($this->digest ? "Weekly digest: {$count} overdue {$noun}" : "You have {$count} overdue {$noun}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->digest
                ? "These {$noun} from root cause analyses are past their due date:"
                : "These {$noun} assigned to you are past their due date:");

        foreach ($this->items->take(20) as $item) {
            $owner = $this->digest ? ' · '.($item->owner->name ?? 'no owner') : '';
            $message->line("- {$item->rca->incident->ref}: {$item->title} (due {$item->due_date?->format('j M')}{$owner})");
        }

        if ($count > 20) {
            $message->line('…and '.($count - 20).' more.');
        }

        return $message->action('Open action items', RcaLinks::actionItems($this->digest));
    }
}
