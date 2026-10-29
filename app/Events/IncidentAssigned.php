<?php

namespace App\Events;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the assignee or team of an incident changes.
 */
class IncidentAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public ?User $previousAssignee,
        public ?User $actor,
    ) {}
}
