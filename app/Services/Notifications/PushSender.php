<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;

/**
 * Sends one encrypted Web Push message. The real implementation talks to the browser push
 * service; tests swap in a fake.
 */
interface PushSender
{
    /** False when the VAPID keys are missing, so nothing can be signed. */
    public function isConfigured(): bool;

    /** The application server key the browser subscribes with. */
    public function publicKey(): ?string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload): PushResult;
}
