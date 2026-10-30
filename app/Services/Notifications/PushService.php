<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use App\Models\User;

/**
 * Queues push messages for a user's subscribed browsers. Sending is queued because each
 * message is an HTTP call to a push service.
 */
class PushService
{
    public function __construct(private readonly PushSender $sender) {}

    public function isAvailable(): bool
    {
        return $this->sender->isConfigured();
    }

    public function publicKey(): ?string
    {
        return $this->sender->publicKey();
    }

    /**
     * Push can actually arrive: the server is configured and this person has a browser
     * subscribed.
     */
    public function canReach(User $user): bool
    {
        return $this->isAvailable() && $user->pushSubscriptions()->exists();
    }

    /**
     * @param  array{title: string, body: string, url: string, tag?: string}  $content
     */
    public function queue(User $user, NotificationEvent $event, array $content, ?int $logId = null): void
    {
        if (! $this->canReach($user)) {
            return;
        }

        SendPushNotification::dispatch($user->id, $event->value, $content, $logId)
            ->onQueue('notifications');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendTo(PushSubscription $subscription, array $payload): PushResult
    {
        return $this->sender->send($subscription, $payload);
    }
}
