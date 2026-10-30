<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentLinkType;
use App\Enums\IncidentStatus;
use App\Events\IncidentStatusChanged;
use App\Exceptions\ApiException;
use App\Models\Incident;
use App\Models\User;
use App\Services\RcaService;
use App\Services\SlaService;
use App\Services\TimeTrackingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an incident to a new status (specification section 6, "Status transitions").
 * Stamps the status timestamps and writes status_history, so MTTA and MTTR need no
 * manual entry.
 */
class TransitionIncident
{
    public function __construct(
        private SlaService $sla,
        private TimeTrackingService $timeTracking,
        private SaveResolution $saveResolution,
        private AssignIncident $assignIncident,
        private RcaService $rcas,
    ) {}

    /**
     * @param  array{note?: string|null, resolution?: array<string, mixed>|null, duplicate_of_id?: int|null}  $data
     */
    public function handle(Incident $incident, ?User $actor, IncidentStatus $target, array $data = []): Incident
    {
        return DB::transaction(function () use ($incident, $actor, $target, $data): Incident {
            // Re-read under a row lock so two concurrent transitions cannot both apply.
            $locked = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $from = $locked->status;
            $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
            $now = now();

            if (! $from->canTransitionTo($target)) {
                throw new ApiException(
                    "An incident cannot move from {$from->value} to {$target->value}.",
                    'invalid_transition',
                );
            }

            match (true) {
                $target === IncidentStatus::Acknowledged => $this->acknowledge($locked, $actor),
                $target === IncidentStatus::Mitigated => $this->mitigate($locked, $note),
                $target === IncidentStatus::Resolved => $this->resolve($locked, $actor, $data['resolution'] ?? null),
                $target === IncidentStatus::Closed => $this->close($locked),
                $target === IncidentStatus::Investigating && in_array($from, [IncidentStatus::Resolved, IncidentStatus::Closed], true) => $this->reopen($locked, $note),
                $target === IncidentStatus::Cancelled => $this->cancel($locked, $actor, $note, $data['duplicate_of_id'] ?? null),
                default => null,
            };

            $previous = $locked->statusHistory()->latest('changed_at')->latest('id')->first();

            $locked->status = $target;
            $locked->save();

            $locked->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $target,
                'user_id' => $actor?->id,
                'note' => $note,
                'changed_at' => $now,
                'seconds_in_previous' => $previous === null ? null : (int) $previous->changed_at->diffInSeconds($now),
            ]);

            $this->sla->recordLateCompletions($locked);

            IncidentStatusChanged::dispatch($locked, $from, $target, $actor);

            return $locked;
        });
    }

    /**
     * An acknowledged incident always has an owner; without one, the acknowledger takes it.
     */
    private function acknowledge(Incident $incident, ?User $actor): void
    {
        if ($incident->assignee_id === null && $actor !== null) {
            $this->assignIncident->handle($incident, $actor, $actor, null, 'Took ownership when acknowledging');
        }

        if ($incident->assignee_id === null) {
            throw new ApiException('Assign the incident before acknowledging it.', 'assignee_required');
        }

        $incident->acknowledged_at ??= now();
        // Someone owns it now: stop automatic escalation.
        $incident->next_escalation_at = null;
    }

    private function mitigate(Incident $incident, ?string $note): void
    {
        $this->requireNote($note, 'Describe what reduced the impact.');

        $incident->mitigated_at = now();
    }

    /**
     * @param  array<string, mixed>|null  $resolution
     */
    private function resolve(Incident $incident, ?User $actor, ?array $resolution): void
    {
        if ($resolution !== null) {
            $this->saveResolution->handle($incident, $actor, $resolution);
        }

        $saved = $incident->resolution()->first();

        if ($saved === null || blank($saved->summary)) {
            throw ValidationException::withMessages([
                'resolution.summary' => ['A resolution summary and fix type are required to resolve an incident.'],
            ]);
        }

        $incident->resolved_at = now();
        $this->timeTracking->stopRunningTimers($incident);
        // The RCA is due a set number of business days after resolution.
        $this->rcas->syncDueDate($incident);
    }

    /**
     * SEV1 and SEV2 need an approved RCA; SEV3 and SEV4 need a completed resolution.
     */
    private function close(Incident $incident): void
    {
        if ($incident->severity->isHigh() && ! $incident->hasApprovedRca()) {
            throw new ApiException(
                "{$incident->severity->label()} incidents need an approved root cause analysis before closing.",
                'rca_required',
            );
        }

        if (! $incident->severity->isHigh() && ! $incident->resolution()->first()?->isComplete()) {
            throw new ApiException(
                'Complete the resolution (summary, fix type and verification) before closing.',
                'resolution_incomplete',
            );
        }

        $incident->closed_at = now();
    }

    /**
     * Reopening counts as a recurrence and clears the resolution timestamps so the incident
     * is open again for SLA and dashboards.
     */
    private function reopen(Incident $incident, ?string $note): void
    {
        $this->requireNote($note, 'Give a reason for reopening.');

        $incident->reopened_count++;
        $incident->resolved_at = null;
        $incident->closed_at = null;
    }

    private function cancel(Incident $incident, ?User $actor, ?string $note, ?int $duplicateOfId): void
    {
        $this->requireNote($note, 'Give a reason, for example false alarm or duplicate.');

        if ($duplicateOfId === null) {
            return;
        }

        if ($duplicateOfId === $incident->id) {
            throw ValidationException::withMessages(['duplicate_of_id' => ['An incident cannot duplicate itself.']]);
        }

        $incident->links()->firstOrCreate(
            ['related_incident_id' => $duplicateOfId, 'type' => IncidentLinkType::Duplicate],
            ['created_by' => $actor?->id],
        );
    }

    private function requireNote(?string $note, string $message): void
    {
        if ($note === null) {
            throw ValidationException::withMessages(['note' => [$message]]);
        }
    }
}
