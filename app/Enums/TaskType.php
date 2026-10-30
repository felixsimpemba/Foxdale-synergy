<?php

namespace App\Enums;

enum TaskType: string
{
    case Task = 'task';
    case Bug = 'bug';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Task',
            self::Bug => 'Bug',
            self::Feature => 'Feature',
        };
    }
}
