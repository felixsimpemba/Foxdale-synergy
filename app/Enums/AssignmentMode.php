<?php

namespace App\Enums;

/**
 * How a routing rule picks the assignee within the target team.
 */
enum AssignmentMode: string
{
    /** The person on call now, else round robin, else the team lead. */
    case OnCall = 'on_call';

    /** The active member who was assigned an incident least recently, else the team lead. */
    case RoundRobin = 'round_robin';

    /** The active member with the fewest open incidents, else the team lead. */
    case LeastLoaded = 'least_loaded';

    /** Route to the team without assigning anyone. */
    case None = 'none';
}
