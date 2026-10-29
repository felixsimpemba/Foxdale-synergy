<?php

namespace App\Enums;

enum IncidentLinkType: string
{
    case Duplicate = 'duplicate';
    case Related = 'related';
    case CausedBy = 'caused_by';
}
