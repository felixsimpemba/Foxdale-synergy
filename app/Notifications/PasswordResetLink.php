<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by "Forgot password" and when an admin sends a reset link from the user page.
 * The link lets the user choose a new password through POST /api/v1/auth/reset-password.
 */
class PasswordResetLink extends Notification implements ShouldQueue
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
        $url = config('incidents.frontend_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->greeting("Hello {$notifiable->name},")
            ->line('We received a request to reset the password for your account.')
            ->action('Choose a new password', $url)
            ->line('This link expires in 60 minutes. If you did not ask for a reset, you can ignore this email.');
    }
}
