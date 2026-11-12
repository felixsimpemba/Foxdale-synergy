<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEvent;
use App\Enums\Severity;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * WhatsApp delivery for notifications: texts to individual phone numbers, and incident
 * alerts to the groups an admin switched on. Sending is queued because every message is
 * an HTTP call to the WAPI gateway.
 */
class WhatsAppNotifier
{
    /** Event + subject pairs already broadcast in this request, so duplicates are dropped. */
    private array $broadcasted = [];

    public function __construct(private readonly WhatsAppClient $client) {}

    public function isAvailable(): bool
    {
        return $this->client->isEnabled();
    }

    /**
     * Queue a text to this user's phone number, if they have one.
     *
     * @param  array{title: string, body: string, url: string}  $content
     */
    public function queueToUser(User $user, NotificationEvent $event, ?Model $subject, array $content): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $number = self::normalizeNumber((string) $user->phone);

        if ($number === '') {
            $this->log($user->id, $event, $subject, $content, NotificationDeliveryStatus::Skipped, 'No phone number on the profile.');

            return;
        }

        $this->queue($number, $event, $subject, $content, $user->id);
    }

    /**
     * Send the event to every active WhatsApp group, once per event and subject.
     *
     * @param  array{title: string, body: string, url: string}  $content
     */
    public function broadcastToGroups(NotificationEvent $event, ?Model $subject, array $content): void
    {
        if (! $this->isAvailable() || ! $event->broadcastsToWhatsAppGroups()) {
            return;
        }

        $key = $event->value.':'.($subject === null ? 'none' : $subject->getMorphClass().':'.$subject->getKey());

        if (isset($this->broadcasted[$key])) {
            return;
        }

        $this->broadcasted[$key] = true;

        $text = $this->groupText($event, $subject, $content);

        WhatsAppGroup::query()
            ->active()
            ->orderBy('subject')
            ->get()
            ->each(fn (WhatsAppGroup $group) => $this->queue(
                $group->group_jid,
                $event,
                $subject,
                $content,
                null,
                $text,
            ));
    }

    /**
     * Group messages carry the incident's key details, not just the notification text.
     *
     * @param  array{title: string, body: string, url: string}  $content
     */
    private function groupText(NotificationEvent $event, ?Model $subject, array $content): string
    {
        if (! $subject instanceof Incident) {
            return self::format($content);
        }

        $subject->loadMissing(['service', 'assignee']);

        $icon = match ($event) {
            NotificationEvent::IncidentCreated, NotificationEvent::SlaBreached => '🚨',
            NotificationEvent::IncidentAssigned => '🔔',
            NotificationEvent::IncidentStatusChanged => '🔄',
            NotificationEvent::SlaWarning => '⏰',
            NotificationEvent::IncidentUpdatePosted => '💬',
            default => '📣',
        };

        $label = match ($event) {
            NotificationEvent::IncidentCreated => 'NEW INCIDENT',
            NotificationEvent::IncidentAssigned => 'INCIDENT ASSIGNED',
            NotificationEvent::IncidentStatusChanged => 'STATUS UPDATE',
            NotificationEvent::SlaWarning => 'SLA WARNING',
            NotificationEvent::SlaBreached => 'SLA BREACHED',
            NotificationEvent::IncidentUpdatePosted => 'NEW UPDATE',
            default => 'INCIDENT UPDATE',
        };

        $severity = match ($subject->severity) {
            Severity::Sev1 => '🔴 SEV1',
            Severity::Sev2 => '🟠 SEV2',
            Severity::Sev3 => '🟡 SEV3',
            Severity::Sev4 => '🟢 SEV4',
        };

        $sections = [
            "{$icon} *{$label} — {$subject->ref}*",
            implode(' · ', array_filter([
                $severity,
                (string) $subject->service?->name,
            ])),
        ];

        $sections[] = match ($event) {
            NotificationEvent::IncidentCreated => "*{$subject->title}*\n"
                .Str::limit(trim((string) $subject->description), 300),
            NotificationEvent::IncidentAssigned => "*{$subject->title}*\nAssigned to *"
                .($subject->assignee?->name ?? 'nobody').'*',
            NotificationEvent::IncidentStatusChanged => "*{$subject->title}*\nNew status: *"
                .ucfirst($subject->status->value).'*',
            NotificationEvent::IncidentUpdatePosted => "*{$subject->title}*\n\n{$content['body']}",
            default => $content['body'],
        };

        $facts = [];

        if ($event !== NotificationEvent::IncidentStatusChanged) {
            $facts[] = '📌 *Status:* '.ucfirst($subject->status->value);
        }

        if ($event !== NotificationEvent::IncidentAssigned) {
            $facts[] = '👤 *Assigned to:* '.($subject->assignee?->name ?? 'Unassigned');
        }

        if ($facts !== []) {
            $sections[] = implode("\n", $facts);
        }

        $sections[] = "🔗 {$content['url']}";

        return implode("\n\n", array_filter($sections, fn (string $section): bool => trim($section) !== ''));
    }

    /**
     * @param  array{title: string, body: string, url: string}  $content
     */
    private function queue(string $number, NotificationEvent $event, ?Model $subject, array $content, ?int $userId, ?string $text = null): void
    {
        $log = $this->log($userId, $event, $subject, $content, NotificationDeliveryStatus::Queued);

        SendWhatsAppMessage::dispatch($number, $text ?? self::format($content), $log->id)
            ->onQueue('notifications');
    }

    /**
     * @param  array{title: string, body: string, url: string}  $content
     */
    private function log(?int $userId, NotificationEvent $event, ?Model $subject, array $content, NotificationDeliveryStatus $status, ?string $error = null): NotificationLog
    {
        return NotificationLog::create([
            'user_id' => $userId,
            'event' => $event,
            'channel' => NotificationChannel::WhatsApp,
            'status' => $status,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'title' => mb_substr($content['title'], 0, 200),
            'error' => $error,
        ]);
    }

    /**
     * @param  array{title: string, body: string, url: string}  $content
     */
    public static function format(array $content): string
    {
        $lines = array_filter(
            ["*{$content['title']}*", $content['body'], "🔗 {$content['url']}"],
            fn (string $line): bool => trim($line) !== '',
        );

        return implode("\n\n", $lines);
    }

    /** Phone numbers go to the gateway as digits only; E.164's leading + is dropped. */
    public static function normalizeNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }
}
