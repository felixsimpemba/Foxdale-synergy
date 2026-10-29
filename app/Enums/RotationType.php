<?php

namespace App\Enums;

/**
 * Describes how a schedule's shifts are laid out. Shifts themselves are stored explicitly.
 */
enum RotationType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Custom = 'custom';
}
