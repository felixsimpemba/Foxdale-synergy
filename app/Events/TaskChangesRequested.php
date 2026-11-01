<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a reviewer sends a task back with changes to make.
 */
class TaskChangesRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Task $task,
        public ?User $actor,
        public string $note,
    ) {}
}
