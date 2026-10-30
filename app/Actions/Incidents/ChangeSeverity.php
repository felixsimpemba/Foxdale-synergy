<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentUpdateType;
use App\Enums\Severity;
use App\Events\IncidentSeverityChanged;
use App\Exceptions\ApiException;
use App\Models\Incident;
use App\Models\User;
use App\Services\SlaService;
use Illuminate\Support\Facades\DB;

/**
 * Change severity with a reason. SLA deadlines are recalculated from reported_at.
 */
class ChangeSeverity
{
    public function __construct(private SlaService $sla) {}

    public function handle(Incident $incident, ?User $actor, Severity $severity, string $reason): Incident
    {
        $from = $incident->severity;

        if ($from === $severity) {
            throw new ApiException("The incident is already {$severity->label()}.", 'severity_unchanged');
        }

        return DB::transaction(function () use ($incident, $actor, $severity, $reason, $from): Incident {
            $incident->severity = $severity;
            $this->sla->applyTargets($incident);
            $incident->save();

            $incident->updates()->create([
                'user_id' => $actor?->id,
                'type' => IncidentUpdateType::System,
                'body' => "Severity changed from {$from->label()} to {$severity->label()}: {$reason}",
            ]);

            IncidentSeverityChanged::dispatch($incident, $from, $severity, $actor);

            return $incident;
        });
    }
}
