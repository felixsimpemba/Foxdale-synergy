<?php

namespace App\Enums;

/**
 * planned → active → completed. A project has at most one active sprint at a time.
 */
enum SprintState: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Active => 'Active',
            self::Completed => 'Completed',
        };
    }

    public function isEditable(): bool
    {
        return $this !== self::Completed;
    }
}
