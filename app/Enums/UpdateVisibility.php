<?php

namespace App\Enums;

/**
 * Internal updates are for responders; stakeholder updates may be shared with the business.
 */
enum UpdateVisibility: string
{
    case Internal = 'internal';
    case Stakeholder = 'stakeholder';
}
