<?php

namespace App\Services\Notifications;

/**
 * The result of sending one push message to one browser.
 */
final readonly class PushResult
{
    public function __construct(public PushOutcome $outcome, public ?string $error = null) {}

    public static function sent(): self
    {
        return new self(PushOutcome::Sent);
    }

    public static function expired(?string $reason = null): self
    {
        return new self(PushOutcome::Expired, $reason);
    }

    public static function failed(?string $reason = null): self
    {
        return new self(PushOutcome::Failed, $reason);
    }
}
