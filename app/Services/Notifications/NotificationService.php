<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEvent;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\InAppAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The one way anything in the system tells a person something (specification section 12):
 * it writes the in-app bell entry, queues browser push and sends email, each only on the
 * channels that person allows. Every message is written to notification_logs.
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly PushService $push,
    ) {}

    /**
     * @param  iterable<int, User|null>  $recipients
     * @param  array{title: string, body: string, url: string}  $content
     * @param  Notification|null  $mail  the email to send, if this event has one
     */
    public function send(iterable $recipients, NotificationEvent $event, ?Model $subject, array $content, ?Notification $mail = null, ?User $actor = null): void
    {
        $sent = [];

        foreach ($recipients as $user) {
            if ($user === null || ! $user->is_active || isset($sent[$user->id])) {
                continue;
            }

            if ($actor !== null && $user->id === $actor->id) {
                continue;
            }

            $sent[$user->id] = true;
            $this->sendTo($user, $event, $subject, $content, $mail);
        }
    }

    /**
     * @param  array{title: string, body: string, url: string}  $content
     */
    public function sendTo(User $user, NotificationEvent $event, ?Model $subject, array $content, ?Notification $mail = null): void
    {
        $channels = $this->preferences->channelsFor($user, $event);

        if ($channels === []) {
            $this->log($user, $event, $subject, $content, null, NotificationDeliveryStatus::Skipped, 'All channels are turned off for this event.');

            return;
        }

        if (in_array(NotificationChannel::InApp, $channels, true)) {
            try {
                $user->notify(new InAppAlert($event, $content));
                $this->log($user, $event, $subject, $content, NotificationChannel::InApp, NotificationDeliveryStatus::Sent);
            } catch (Throwable $error) {
                report($error);
                $this->log($user, $event, $subject, $content, NotificationChannel::InApp, NotificationDeliveryStatus::Failed, $error->getMessage());
            }
        }

        if ($mail !== null && in_array(NotificationChannel::Email, $channels, true)) {
            try {
                $user->notify($mail);
                $this->log($user, $event, $subject, $content, NotificationChannel::Email, NotificationDeliveryStatus::Queued);
            } catch (Throwable $error) {
                report($error);
                $this->log($user, $event, $subject, $content, NotificationChannel::Email, NotificationDeliveryStatus::Failed, $error->getMessage());
            }
        }

        if (in_array(NotificationChannel::Push, $channels, true) && $this->push->canReach($user)) {
            $log = $this->log($user, $event, $subject, $content, NotificationChannel::Push, NotificationDeliveryStatus::Queued);
            $this->push->queue($user, $event, $content, $log->id);
        }
    }

    /**
     * @param  array{title: string, body: string, url: string}  $content
     */
    private function log(User $user, NotificationEvent $event, ?Model $subject, array $content, ?NotificationChannel $channel, NotificationDeliveryStatus $status, ?string $error = null): NotificationLog
    {
        return NotificationLog::create([
            'user_id' => $user->id,
            'event' => $event,
            'channel' => $channel ?? NotificationChannel::InApp,
            'status' => $status,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'title' => mb_substr($content['title'], 0, 200),
            'error' => $error,
            'sent_at' => $status === NotificationDeliveryStatus::Sent ? now() : null,
        ]);
    }
}
