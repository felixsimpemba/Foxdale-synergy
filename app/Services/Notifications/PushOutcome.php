<?php

namespace App\Services\Notifications;

/**
 * What happened to one push message.
 */
enum PushOutcome
{
    case Sent;
    case Expired;
    case Failed;
}
