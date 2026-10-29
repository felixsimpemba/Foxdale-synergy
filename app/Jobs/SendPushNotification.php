<?php

namespace App\Jobs;

use App\Enums\NotificationDeliveryStatus;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notifications\PushOutcome;
use App\Services\Notifications\PushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes one message to every browser the user subscribed. Browsers that answer 404/410
 * (uninstalled, permission revoked, data cleared) are forgotten. One failing browser does
 * not stop the others.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array{title: string, body: string, url: string, tag?: string}  $content
     */
    public function __construct(
        public int $userId,
        public string $event,
        public array $content,
        public ?int $logId = null,
    ) {}

    public function handle(PushService $push): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $user->is_active) {
            return;
        }

        $sent = 0;
        $error = null;

        foreach ($user->pushSubscriptions()->get() as $subscription) {
            $result = $push->sendTo($subscription, [...$this->content, 'event' => $this->event]);

            match ($result->outcome) {
                PushOutcome::Sent => $sent++,
                PushOutcome::Expired => $subscription->delete(),
                PushOutcome::Failed => $error = $result->error,
            };

            if ($result->outcome === PushOutcome::Sent) {
                $subscription->forceFill(['last_used_at' => now()])->saveQuietly();
            }
        }

        $log = $this->logId === null ? null : NotificationLog::query()->find($this->logId);

        $log?->forceFill([
            'status' => $sent > 0 ? NotificationDeliveryStatus::Sent : NotificationDeliveryStatus::Failed,
            'error' => $sent > 0 ? null : $error ?? 'No subscribed browser accepted the message.',
            'sent_at' => $sent > 0 ? now() : null,
        ])->saveQuietly();
    }
}
