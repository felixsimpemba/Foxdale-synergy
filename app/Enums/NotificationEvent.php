<?php

namespace App\Enums;

/**
 * Things worth telling someone about, with the channels each starts with. Users can turn
 * channels off per event; critical events ignore quiet hours.
 */
enum NotificationEvent: string
{
    case IncidentCreated = 'incident.created';
    case IncidentAssigned = 'incident.assigned';
    case IncidentMention = 'incident.mention';
    case IncidentStatusChanged = 'incident.status_changed';
    case SlaWarning = 'sla.warning';
    case SlaBreached = 'sla.breached';
    case RcaReadyForReview = 'rca.ready_for_review';
    case RcaRejected = 'rca.rejected';
    case RcaDue = 'rca.due';
    case ActionItemOverdue = 'action_item.overdue';
    case TaskAssigned = 'task.assigned';
    case TaskMention = 'task.mention';
    case TaskComment = 'task.comment';
    case TaskReply = 'task.reply';
    case TaskReviewRequested = 'task.review_requested';
    case TaskChangesRequested = 'task.changes_requested';
    case TaskApproved = 'task.approved';

    public function label(): string
    {
        return match ($this) {
            self::IncidentCreated => 'An incident is reported on my team',
            self::IncidentAssigned => 'An incident is assigned to me',
            self::IncidentMention => 'Someone mentions me in a comment',
            self::IncidentStatusChanged => 'Status changes on incidents I reported or watch',
            self::SlaWarning => 'An incident I own is close to an SLA deadline',
            self::SlaBreached => 'An incident I own misses an SLA target',
            self::RcaReadyForReview => 'A root cause analysis is ready for review',
            self::RcaRejected => 'My root cause analysis is returned',
            self::RcaDue => 'A root cause analysis is overdue',
            self::ActionItemOverdue => 'One of my action items is overdue',
            self::TaskAssigned => 'A task is assigned to me',
            self::TaskMention => 'Someone mentions me in a task comment',
            self::TaskComment => 'Someone comments on a task I am involved in',
            self::TaskReply => 'Someone replies to my task comment',
            self::TaskReviewRequested => 'A task is waiting for my review',
            self::TaskChangesRequested => 'Changes are requested on my task',
            self::TaskApproved => 'My task is approved',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::IncidentCreated, self::IncidentAssigned, self::IncidentMention,
            self::IncidentStatusChanged, self::SlaWarning, self::SlaBreached => 'Incidents',
            self::TaskAssigned, self::TaskMention, self::TaskComment, self::TaskReply,
            self::TaskReviewRequested, self::TaskChangesRequested, self::TaskApproved => 'Projects',
            default => 'Root cause analysis',
        };
    }

    /**
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::IncidentCreated, self::IncidentMention,
            self::IncidentAssigned => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::IncidentStatusChanged => [NotificationChannel::InApp, NotificationChannel::Push],
            self::SlaWarning => [NotificationChannel::InApp, NotificationChannel::Push],
            self::SlaBreached => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::RcaReadyForReview, self::RcaRejected => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::RcaDue => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::ActionItemOverdue => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::TaskAssigned, self::TaskMention, self::TaskReviewRequested,
            self::TaskChangesRequested, self::TaskApproved => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::TaskComment, self::TaskReply => [NotificationChannel::InApp, NotificationChannel::Push],
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
