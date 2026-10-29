<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentCreated;
use App\Models\Incident;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\RoutingService;
use App\Services\SlaService;
use Illuminate\Support\Facades\DB;

/**
 * Report an incident: store it, give it a reference, route it, and start the SLA clocks
 * and the escalation timer.
 */
class CreateIncident
{
    public function __construct(
        private RoutingService $routing,
        private SlaService $sla,
        private EscalationService $escalation,
        private AssignIncident $assignIncident,
    ) {}

    /**
     * @param  array{title: string, description: string, severity: int, service_id?: int|null, category_id?: int|null, impact?: string|null, started_at?: string|null, source?: IncidentSource|null, fingerprint?: string|null, assignee_id?: int|null}  $data
     */
    public function handle(?User $reporter, array $data): Incident
    {
        return DB::transaction(function () use ($reporter, $data): Incident {
            $incident = new Incident([
                'title' => $data['title'],
                'description' => $data['description'],
                'impact' => $data['impact'] ?? null,
                'severity' => Severity::from((int) $data['severity']),
                'status' => IncidentStatus::Reported,
                'service_id' => $data['service_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'reporter_id' => $reporter?->id,
                'source' => $data['source'] ?? IncidentSource::Manual,
                'fingerprint' => $data['fingerprint'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'reported_at' => now(),
            ]);

            $decision = $this->routing->route($incident);
            $incident->setRelation('escalationPolicy', $decision->escalationPolicy);
            $this->sla->applyTargets($incident);
            $this->escalation->start($incident);
            $incident->save();

            $incident->forceFill(['ref' => Incident::makeRef($incident->id, $incident->reported_at)])->saveQuietly();

            $incident->statusHistory()->create([
                'to_status' => IncidentStatus::Reported,
                'user_id' => $reporter?->id,
                'changed_at' => $incident->reported_at,
            ]);

            if ($incident->assignee_id !== null || $incident->team_id !== null) {
                $incident->assignments()->create([
                    'to_user_id' => $incident->assignee_id,
                    'team_id' => $incident->team_id,
                    'reason' => $decision->rule === null
                        ? 'Routed to the default team (no routing rule matched)'
                        : "Routed by rule: {$decision->rule->name}",
                ]);
            }

            // Someone was picked while reporting: give them the incident now, which also
            // tells them about it.
            if (filled($data['assignee_id'] ?? null)) {
                $incident->load('assignee');

                $this->assignIncident->handle(
                    $incident,
                    $reporter,
                    User::query()->findOrFail((int) $data['assignee_id']),
                    null,
                    'Chosen when reporting the incident',
                );
            }

            IncidentCreated::dispatch($incident);

            return $incident;
        });
    }
}
