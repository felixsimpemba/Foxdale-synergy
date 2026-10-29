<?php

namespace App\Enums;

enum TimeEntrySource: string
{
    case Manual = 'manual';
    case Timer = 'timer';
}
