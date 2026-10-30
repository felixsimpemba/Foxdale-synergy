<?php

namespace App\Enums;

/**
 * open → in_progress → done, or wont_do with a reason.
 */
enum ActionItemStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case WontDo = 'wont_do';

    public function isFinished(): bool
    {
        return $this === self::Done || $this === self::WontDo;
    }

    /**
     * @return list<string>
     */
    public static function unfinishedValues(): array
    {
        return [self::Open->value, self::InProgress->value];
    }
}
