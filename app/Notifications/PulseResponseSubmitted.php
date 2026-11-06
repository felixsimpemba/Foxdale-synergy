<?php

namespace App\Notifications;

use App\Models\PulseResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email admins get when somebody submits the Department Pulse survey. Queued on the
 * notifications queue, like every other email in the system.
 */
class PulseResponseSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PulseResponse $response)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reported = $this->response->answers['problems'] ?? [];
        $problems = array_values(array_filter(
            is_array($reported) ? $reported : [],
            fn (mixed $problem): bool => is_array($problem) && trim((string) ($problem['text'] ?? '')) !== '',
        ));
        $biggest = $problems[0] ?? null;
        $count = count($problems);

        $message = (new MailMessage)
            ->subject("New Department Pulse response: {$this->response->name} ({$this->response->departmentName()})")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->response->name} ({$this->response->role}) from {$this->response->departmentName()} submitted the Department Pulse survey.")
            ->line($count.' problem'.($count === 1 ? '' : 's').' reported.');

        if (is_array($biggest) && ($biggest['text'] ?? '') !== '') {
            $message->line('Biggest problem: '.$biggest['text']);
        }

        return $message
            ->action('Open the responses dashboard', $this->url())
            ->line('You can turn these emails off in Notification settings.');
    }

    private function url(): string
    {
        return rtrim((string) config('incidents.frontend_url'), '/').'/admin/pulse?response='.$this->response->id;
    }
}
