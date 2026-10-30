<?php

namespace App\Events;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after every status transition, including acknowledge, resolve, reopen and cancel.
 */
class IncidentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public IncidentStatus $from,
        public IncidentStatus $to,
        public ?User $actor,
    ) {}
}
