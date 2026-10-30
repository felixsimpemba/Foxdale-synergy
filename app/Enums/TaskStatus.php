<?php

namespace App\Enums;

/**
 * The four board columns. "Blocked" is a flag on the task, not a column, so the board
 * stays usable on a phone.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::InReview => 'In review',
            self::Done => 'Done',
        };
    }

    public function isFinished(): bool
    {
        return $this === self::Done;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => ! $status->isFinished()),
        ));
    }
}
