<?php

namespace App\Enums;

enum IncidentUpdateType: string
{
    case Comment = 'comment';
    case StatusUpdate = 'status_update';
    case System = 'system';
    case Ai = 'ai';
}
