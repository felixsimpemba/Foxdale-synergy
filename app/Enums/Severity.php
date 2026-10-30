<?php

namespace App\Enums;

/**
 * SEV1 is the most severe. Stored as a tinyint 1-4.
 */
enum Severity: int
{
    case Sev1 = 1;
    case Sev2 = 2;
    case Sev3 = 3;
    case Sev4 = 4;

    public function label(): string
    {
        return 'SEV'.$this->value;
    }

    /**
     * SEV1 and SEV2 need an approved RCA before closing.
     */
    public function isHigh(): bool
    {
        return $this->value <= 2;
    }
}
