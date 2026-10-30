<?php

namespace App\Enums;

/**
 * Time series on the dashboard: counts of opened and resolved incidents, or average minutes
 * to acknowledge and to resolve.
 */
enum DashboardMetric: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';
    case Mtta = 'mtta';
    case Mttr = 'mttr';

    /**
     * The timestamp that places an incident in a bucket.
     */
    public function column(): string
    {
        return match ($this) {
            self::Opened => 'reported_at',
            self::Resolved, self::Mttr => 'resolved_at',
            self::Mtta => 'acknowledged_at',
        };
    }

    public function isDuration(): bool
    {
        return in_array($this, [self::Mtta, self::Mttr], true);
    }
}
