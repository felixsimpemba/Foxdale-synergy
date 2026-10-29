<?php

namespace App\Services\Scanning;

use App\Enums\ScanStatus;

/**
 * Used when INCIDENTS_VIRUS_SCANNER=none (the default): files are stored unscanned.
 */
class NullVirusScanner implements VirusScanner
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function scan(string $disk, string $path): ScanStatus
    {
        return ScanStatus::NotScanned;
    }
}
