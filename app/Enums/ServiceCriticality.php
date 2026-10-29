<?php

namespace App\Enums;

enum ServiceCriticality: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
