<?php

namespace App\Enums;

/**
 * Things worth telling someone about, with the channels each starts with. Users can turn
 * channels off per event; critical events ignore quiet hours.
 */
enum NotificationEvent: string
{
    case IncidentAssigned = 'incident.assigned';
    case IncidentStatusChanged = 'incident.status_changed';
    case SlaWarning = 'sla.warning';
    case SlaBreached = 'sla.breached';
    case RcaReadyForReview = 'rca.ready_for_review';
    case RcaRejected = 'rca.rejected';
    case RcaDue = 'rca.due';
    case ActionItemOverdue = 'action_item.overdue';

    public function label(): string
    {
        return match ($this) {
            self::IncidentAssigned => 'An incident is assigned to me',
            self::IncidentStatusChanged => 'Status changes on incidents I reported or watch',
            self::SlaWarning => 'An incident I own is close to an SLA deadline',
            self::SlaBreached => 'An incident I own misses an SLA target',
            self::RcaReadyForReview => 'A root cause analysis is ready for review',
            self::RcaRejected => 'My root cause analysis is returned',
            self::RcaDue => 'A root cause analysis is overdue',
            self::ActionItemOverdue => 'One of my action items is overdue',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::IncidentAssigned, self::IncidentStatusChanged, self::SlaWarning, self::SlaBreached => 'Incidents',
            default => 'Root cause analysis',
        };
    }

    /**
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::IncidentAssigned => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::IncidentStatusChanged => [NotificationChannel::InApp, NotificationChannel::Push],
            self::SlaWarning => [NotificationChannel::InApp, NotificationChannel::Push],
            self::SlaBreached => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::RcaReadyForReview, self::RcaRejected => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::RcaDue => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::ActionItemOverdue => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
        };
    }

    /**
     * Delivered even during quiet hours (quiet hours only mute browser push).
     */
    public function isCritical(): bool
    {
        return in_array($this, [self::SlaBreached, self::RcaDue], true);
    }
}
