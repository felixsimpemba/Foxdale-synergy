<?php

namespace App\Enums;

enum SlaEventType: string
{
    case Warning = 'warning';
    case Breached = 'breached';
}
