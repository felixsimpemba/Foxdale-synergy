<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Services\EscalationService;
use App\Services\SlaService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs every minute: raises SLA warnings (80% elapsed) and breaches on open incidents, and
 * escalates unacknowledged incidents whose next escalation level is due.
 */
class CheckSlaTimers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 50;

    public int $uniqueFor = 60;

    public function handle(SlaService $sla, EscalationService $escalation): void
    {
        $now = now();

        Incident::query()
            ->where('status', IncidentStatus::Reported->value)
            ->where('next_escalation_at', '<=', $now)
            ->with('escalationPolicy.levels', 'team.lead')
            ->chunkById(100, function (Collection $incidents) use ($escalation, $now): void {
                $incidents->each(fn (Incident $incident) => $escalation->escalateIfDue($incident, $now));
            });

        Incident::query()
            ->open()
            ->where(fn ($query) => $query
                ->where(fn ($ack) => $ack->whereNull('acknowledged_at')->whereNotNull('ack_due_at'))
                ->orWhere(fn ($mitigate) => $mitigate->whereNull('mitigated_at')->whereNotNull('mitigate_due_at'))
                ->orWhere(fn ($resolve) => $resolve->whereNull('resolved_at')->whereNotNull('resolve_due_at')))
            ->chunkById(200, function (Collection $incidents) use ($sla, $now): void {
                $incidents->each(fn (Incident $incident) => $sla->evaluate($incident, $now));
            });
    }
}
