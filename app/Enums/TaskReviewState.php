<?php

namespace App\Enums;

/**
 * The review of a task before it can be marked done. Approval is separate from status:
 * a task is in_review while the reviewer decides.
 */
enum TaskReviewState: string
{
    case None = 'none';
    case Requested = 'requested';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not requested',
            self::Requested => 'Waiting for review',
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes requested',
        };
    }
}
