<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Actions\Incidents\AcknowledgeIncident;
use App\Actions\Incidents\AssignIncident;
use App\Actions\Incidents\ChangeSeverity;
use App\Actions\Incidents\TransitionIncident;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\TokenAbility;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\PresentsIncidents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\AcknowledgeIncidentRequest;
use App\Http\Requests\Incidents\AssignIncidentRequest;
use App\Http\Requests\Incidents\ChangeSeverityRequest;
use App\Http\Requests\Incidents\ReopenIncidentRequest;
use App\Http\Requests\Incidents\TransitionIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use App\Services\EscalationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Status changes, ownership and severity.
 */
class IncidentWorkflowController extends Controller
{
    use PresentsIncidents;

    public function acknowledge(AcknowledgeIncidentRequest $request, Incident $incident, AcknowledgeIncident $acknowledge): IncidentResource
    {
        Gate::authorize('acknowledge', $incident);

        $acknowledge->handle($incident, $request->user(), $request->validated('note'));

        return $this->presentIncident($incident);
    }

    public function transition(TransitionIncidentRequest $request, Incident $incident, TransitionIncident $transition): IncidentResource
    {
        $target = IncidentStatus::from($request->validated('status'));

        match ($target) {
            IncidentStatus::Acknowledged => Gate::authorize('acknowledge', $incident),
            IncidentStatus::Closed => Gate::authorize('close', $incident),
            default => Gate::authorize('transition', $incident),
        };

        // Closing stays a human decision: never with a script or MCP token.
        if ($target === IncidentStatus::Closed && ! $request->user()->tokenCan(TokenAbility::Full->value)) {
            throw new ApiException('Closing an incident requires a signed-in session.', 'forbidden', 403);
        }

        $transition->handle($incident, $request->user(), $target, $request->validated());

        return $this->presentIncident($incident);
    }

    /**
     * Reopen a resolved or closed incident; counts as a recurrence.
     */
    public function reopen(ReopenIncidentRequest $request, Incident $incident, TransitionIncident $transition): IncidentResource
    {
        Gate::authorize('transition', $incident);

        if (! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            throw new ApiException('Only resolved or closed incidents can be reopened.', 'invalid_transition');
        }

        $transition->handle($incident, $request->user(), IncidentStatus::Investigating, ['note' => $request->validated('reason')]);

        return $this->presentIncident($incident);
    }

    public function assign(AssignIncidentRequest $request, Incident $incident, AssignIncident $assign): IncidentResource
    {
        Gate::authorize('assign', $incident);

        $assign->handle(
            $incident,
            $request->user(),
            $request->filled('assignee_id') ? User::findOrFail($request->integer('assignee_id')) : null,
            $request->filled('team_id') ? Team::findOrFail($request->integer('team_id')) : null,
            $request->validated('reason'),
        );

        return $this->presentIncident($incident);
    }

    public function severity(ChangeSeverityRequest $request, Incident $incident, ChangeSeverity $changeSeverity): IncidentResource
    {
        Gate::authorize('changeSeverity', $incident);

        $changeSeverity->handle(
            $incident,
            $request->user(),
            Severity::from((int) $request->validated('severity')),
            $request->validated('reason'),
        );

        return $this->presentIncident($incident);
    }

    /**
     * Manual escalation: notify the next level of the incident's escalation policy now.
     */
    public function escalate(Request $request, Incident $incident, EscalationService $escalation): IncidentResource
    {
        Gate::authorize('assign', $incident);

        $escalation->escalate($incident->load('escalationPolicy.levels', 'team'), $request->user());

        return $this->presentIncident($incident);
    }
}
