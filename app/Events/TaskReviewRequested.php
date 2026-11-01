<?php

namespace App\Events;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a task goes to review and a reviewer has to decide.
 */
class TaskReviewRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Task $task,
        public User $reviewer,
        public ?User $actor,
    ) {}
}
