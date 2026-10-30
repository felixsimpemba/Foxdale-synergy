<?php

namespace App\Services\Scanning;

use App\Enums\ScanStatus;

/**
 * Scans a stored upload. Implementations return Clean or Quarantined; NotScanned means no
 * scanner is configured.
 */
interface VirusScanner
{
    public function isEnabled(): bool;

    public function scan(string $disk, string $path): ScanStatus;
}
