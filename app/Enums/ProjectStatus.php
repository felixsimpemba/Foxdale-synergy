<?php

namespace App\Enums;

/**
 * A project stays readable when paused or archived; archived projects are hidden from the
 * default project list.
 */
enum ProjectStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Archived => 'Archived',
        };
    }
}
