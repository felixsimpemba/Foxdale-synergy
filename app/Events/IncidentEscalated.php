<?php

namespace App\Events;

use App\Models\EscalationLevel;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an incident moves to the next escalation level, automatically or by hand.
 */
class IncidentEscalated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Collection<int, User>  $recipients
     */
    public function __construct(
        public Incident $incident,
        public EscalationLevel $level,
        public Collection $recipients,
        public ?User $actor,
    ) {}
}
