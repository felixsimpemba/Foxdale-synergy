<?php

namespace App\Enums;

/**
 * System entries in a task timeline. Comments join the same feed later from their own table.
 */
enum TaskActivityType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case SprintChanged = 'sprint_changed';
    case PriorityChanged = 'priority_changed';
    case DueDateChanged = 'due_date_changed';
    case ReviewRequested = 'review_requested';
    case ReviewApproved = 'review_approved';
    case ChangesRequested = 'changes_requested';
    case Updated = 'updated';
}
