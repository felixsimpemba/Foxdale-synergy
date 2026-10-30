<?php

namespace App\Enums;

enum IncidentSource: string
{
    case Manual = 'manual';
    case Monitoring = 'monitoring';
    case Mcp = 'mcp';
}
