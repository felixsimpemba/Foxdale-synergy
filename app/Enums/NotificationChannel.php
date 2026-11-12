<?php

namespace App\Enums;

/**
 * Where a notification can be delivered: the in-app bell, browser push, email, or WhatsApp.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Push = 'push';
    case Email = 'email';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In the app',
            self::Push => 'Browser push',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
