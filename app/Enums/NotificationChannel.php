<?php

namespace App\Enums;

/**
 * Where a notification can be delivered: the in-app bell, browser push, or email.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Push = 'push';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In the app',
            self::Push => 'Browser push',
            self::Email => 'Email',
        };
    }
}
