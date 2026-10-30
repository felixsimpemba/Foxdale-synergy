<?php

namespace App\Enums;

enum RecurrenceRisk: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
