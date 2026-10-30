<?php

namespace App\Enums;

/**
 * What a person may do inside one project. Managers also manage the project itself
 * (members, sprints, archive) without needing a global role.
 */
enum ProjectMemberRole: string
{
    case Manager = 'manager';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    public function canManage(): bool
    {
        return $this === self::Manager;
    }

    public function canContribute(): bool
    {
        return $this !== self::Viewer;
    }
}
