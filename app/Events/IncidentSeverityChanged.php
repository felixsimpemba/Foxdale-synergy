<?php

namespace App\Events;

use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after the severity changes and SLA targets are recalculated.
 */
class IncidentSeverityChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public Severity $from,
        public Severity $to,
        public ?User $actor,
    ) {}
}
