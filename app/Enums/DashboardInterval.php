<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Bucket size for dashboard trends. Weeks start on Monday.
 */
enum DashboardInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function bucketStart(CarbonImmutable $local): CarbonImmutable
    {
        return match ($this) {
            self::Day => $local->startOfDay(),
            self::Week => $local->startOfWeek(CarbonImmutable::MONDAY),
            self::Month => $local->startOfMonth(),
        };
    }

    public function next(CarbonImmutable $bucketStart): CarbonImmutable
    {
        return match ($this) {
            self::Day => $bucketStart->addDay(),
            self::Week => $bucketStart->addWeek(),
            self::Month => $bucketStart->addMonthNoOverflow(),
        };
    }
}
