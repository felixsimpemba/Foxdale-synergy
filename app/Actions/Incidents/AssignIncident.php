<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Events\IncidentAssigned;
use App\Exceptions\ApiException;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reassign an incident to a person, a team, or both, and record who did it and why.
 */
class AssignIncident
{
    public function handle(Incident $incident, ?User $actor, ?User $assignee, ?Team $team, ?string $reason): Incident
    {
        if (in_array($incident->status, [IncidentStatus::Closed, IncidentStatus::Cancelled], true)) {
            throw new ApiException('Closed and cancelled incidents cannot be reassigned.', 'incident_closed');
        }

        if ($assignee !== null && ! $assignee->is_active) {
            throw ValidationException::withMessages(['assignee_id' => ['The selected user is deactivated.']]);
        }

        return DB::transaction(function () use ($incident, $actor, $assignee, $team, $reason): Incident {
            $previousAssignee = $incident->assignee;

            $incident->assignments()->create([
                'from_user_id' => $incident->assignee_id,
                'to_user_id' => $assignee->id ?? $incident->assignee_id,
                'team_id' => $team->id ?? $incident->team_id,
                'assigned_by' => $actor?->id,
                'reason' => $reason,
            ]);

            $incident->forceFill(array_filter([
                'assignee_id' => $assignee?->id,
                'team_id' => $team?->id,
            ]))->save();

            IncidentAssigned::dispatch($incident, $previousAssignee, $actor);

            return $incident->setRelation('assignee', $assignee ?? $previousAssignee);
        });
    }
}
