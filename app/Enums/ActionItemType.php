<?php

namespace App\Enums;

/**
 * Corrective fixes this incident, preventive stops it happening again, detection finds it
 * sooner, process changes how people work.
 */
enum ActionItemType: string
{
    case Corrective = 'corrective';
    case Preventive = 'preventive';
    case Detection = 'detection';
    case Process = 'process';
}
