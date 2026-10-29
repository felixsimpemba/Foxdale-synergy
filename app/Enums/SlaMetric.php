<?php

namespace App\Enums;

/**
 * The three SLA targets tracked per incident.
 */
enum SlaMetric: string
{
    case Acknowledge = 'ack';
    case Mitigate = 'mitigate';
    case Resolve = 'resolve';

    /**
     * Column on incidents holding the deadline.
     */
    public function dueColumn(): string
    {
        return match ($this) {
            self::Acknowledge => 'ack_due_at',
            self::Mitigate => 'mitigate_due_at',
            self::Resolve => 'resolve_due_at',
        };
    }

    /**
     * Column on incidents stamped when the target is met.
     */
    public function metColumn(): string
    {
        return match ($this) {
            self::Acknowledge => 'acknowledged_at',
            self::Mitigate => 'mitigated_at',
            self::Resolve => 'resolved_at',
        };
    }
}
