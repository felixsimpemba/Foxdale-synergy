<?php

namespace App\Events;

use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a comment or reply is stored so the people involved can be told.
 */
class TaskCommentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TaskComment $comment,
        public ?User $actor,
    ) {}
}
