<?php

namespace App\Enums;

/**
 * What the dashboard breakdown groups incidents by.
 */
enum DashboardDimension: string
{
    case Service = 'service';
    case Category = 'category';
    case Team = 'team';
    case Severity = 'severity';
    case Source = 'source';

    public function column(): string
    {
        return match ($this) {
            self::Service => 'service_id',
            self::Category => 'category_id',
            self::Team => 'team_id',
            self::Severity => 'severity',
            self::Source => 'source',
        };
    }
}
