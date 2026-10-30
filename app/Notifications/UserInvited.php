<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an admin creates an account. The link lets the user choose a password
 * through POST /api/v1/auth/accept-invite.
 */
class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token)
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
        $url = config('incidents.frontend_url').'/accept-invite?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('You have been invited to '.config('app.name'))
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you on the incident management system.')
            ->action('Set your password', $url)
            ->line('This link expires in 7 days.');
    }
}
