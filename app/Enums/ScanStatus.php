<?php

namespace App\Enums;

/**
 * Virus scan result of an uploaded file. Quarantined files cannot be downloaded.
 */
enum ScanStatus: string
{
    case NotScanned = 'not_scanned';
    case Pending = 'pending';
    case Clean = 'clean';
    case Quarantined = 'quarantined';
}
