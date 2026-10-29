<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push with VAPID signing and encrypted payloads (minishlink/web-push). Messages are
 * small on purpose: push payloads are limited to about 4 KB.
 */
class WebPushSender implements PushSender
{
    public function __construct(
        private readonly ?string $subject,
        private readonly ?string $publicKey,
        private readonly ?string $privateKey,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->subject) && filled($this->publicKey) && filled($this->privateKey);
    }

    public function publicKey(): ?string
    {
        return $this->publicKey;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload): PushResult
    {
        if (! $this->isConfigured()) {
            return PushResult::failed('Web Push is not configured (VAPID keys missing).');
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) $this->subject,
                    'publicKey' => (string) $this->publicKey,
                    'privateKey' => (string) $this->privateKey,
                ],
            ]);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                (string) json_encode($payload, JSON_THROW_ON_ERROR),
                ['TTL' => 43_200],
            );

            if ($report->isSuccess()) {
                return PushResult::sent();
            }

            if ($report->isSubscriptionExpired()) {
                return PushResult::expired($report->getReason());
            }

            return PushResult::failed($report->getReason());
        } catch (Throwable $error) {
            report($error);

            return PushResult::failed($error->getMessage());
        }
    }
}
