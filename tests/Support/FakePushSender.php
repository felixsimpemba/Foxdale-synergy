<?php

namespace Tests\Support;

use App\Models\PushSubscription;
use App\Services\Notifications\PushResult;
use App\Services\Notifications\PushSender;

/**
 * Records pushes instead of calling the browser push service.
 */
class FakePushSender implements PushSender
{
    /** @var list<array{subscription: PushSubscription, payload: array<string, mixed>}> */
    public array $sent = [];

    public ?PushResult $result = null;

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function publicKey(): ?string
    {
        return $this->configured ? 'BEl62iUYgUivxIkv69yViEuiBIa-test-key' : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload): PushResult
    {
        $this->sent[] = ['subscription' => $subscription, 'payload' => $payload];

        return $this->result ?? PushResult::sent();
    }
}
