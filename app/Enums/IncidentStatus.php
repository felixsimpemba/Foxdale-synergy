<?php

namespace App\Enums;

/**
 * The incident lifecycle (specification section 6, "Status transitions").
 */
enum IncidentStatus: string
{
    case Reported = 'reported';
    case Acknowledged = 'acknowledged';
    case Investigating = 'investigating';
    case Mitigated = 'mitigated';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reported => [self::Acknowledged, self::Cancelled],
            self::Acknowledged => [self::Investigating, self::Cancelled],
            self::Investigating => [self::Mitigated, self::Resolved],
            self::Mitigated => [self::Resolved],
            // Moving back to investigating is a reopen and counts as a recurrence.
            self::Resolved => [self::Closed, self::Investigating],
            self::Closed => [self::Investigating],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Open incidents count towards workload, dashboards and SLA checks.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isOpen()),
        ));
    }
}
