<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Take ownership": the acknowledging user becomes the assignee and acknowledged_at is
 * stamped, which gives the time to acknowledge.
 */
class AcknowledgeIncident
{
    public function __construct(private AssignIncident $assignIncident, private TransitionIncident $transitionIncident) {}

    public function handle(Incident $incident, User $actor, ?string $note = null): Incident
    {
        return DB::transaction(function () use ($incident, $actor, $note): Incident {
            if ($incident->status === IncidentStatus::Reported && $incident->assignee_id !== $actor->id) {
                $this->assignIncident->handle($incident, $actor, $actor, null, 'Took ownership when acknowledging');
            }

            return $this->transitionIncident->handle($incident, $actor, IncidentStatus::Acknowledged, ['note' => $note]);
        });
    }
}
