<?php

namespace App\Enums;

/**
 * draft → in_review → approved, or → rejected → (edited) → in_review again.
 */
enum RcaStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * The author can still change the analysis.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
