<?php

namespace App\Http\Resources;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Durations in "timings" are seconds; work_minutes is the logged time. They are filled when
 * the incident was loaded with Incident::scopeWithTimings().
 *
 * @mixin Incident
 */
class IncidentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'title' => $this->title,
            'description' => $this->description,
            'impact' => $this->impact,
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'status' => $this->status,
            'source' => $this->source,
            'fingerprint' => $this->fingerprint,
            'occurrences' => $this->occurrences,
            'reopened_count' => $this->reopened_count,
            'service' => $this->whenLoaded('service', fn () => $this->summary($this->service)),
            'category' => $this->whenLoaded('category', fn () => $this->summary($this->category)),
            'reporter' => $this->whenLoaded('reporter', fn () => $this->summary($this->reporter)),
            'team_id' => $this->team_id,
            'assignee_id' => $this->assignee_id,
            'routing' => $this->when($this->relationLoaded('team') && $this->relationLoaded('assignee'), fn () => [
                'team' => $this->summary($this->team),
                'assignee' => $this->summary($this->assignee),
                'rule_id' => $this->routing_rule_id,
                'escalation_policy_id' => $this->escalation_policy_id,
                'escalation_level' => $this->escalation_level,
                'escalates_at' => $this->next_escalation_at?->toIso8601ZuluString(),
            ]),
            'sla' => [
                'ack_due' => $this->ack_due_at?->toIso8601ZuluString(),
                'mitigate_due' => $this->mitigate_due_at?->toIso8601ZuluString(),
                'resolve_due' => $this->resolve_due_at?->toIso8601ZuluString(),
                'breached' => $this->sla_breached,
            ],
            'timings' => [
                'time_to_ack' => $this->secondsToAcknowledge(),
                'time_to_mitigate' => $this->secondsToMitigate(),
                'time_to_resolve' => $this->secondsToResolve(),
                'time_in_status' => $this->secondsInCurrentStatus(),
                'work_minutes' => $this->workMinutes(),
            ],
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'reported_at' => $this->reported_at->toIso8601ZuluString(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601ZuluString(),
            'mitigated_at' => $this->mitigated_at?->toIso8601ZuluString(),
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'updates' => IncidentUpdateResource::collection($this->whenLoaded('updates')),
            'history' => StatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'time_entries' => TimeEntryResource::collection($this->whenLoaded('timeEntries')),
            'resolution' => $this->whenLoaded('resolution', fn () => $this->resolution === null ? null : new IncidentResolutionResource($this->resolution)),
            // Status and due date only; the full RCA is at GET /incidents/{id}/rca.
            'rca' => $this->whenLoaded('rca', fn () => $this->rca === null ? null : [
                'id' => $this->rca->id,
                'status' => $this->rca->status,
                'due_at' => $this->rca->due_at?->toIso8601ZuluString(),
                'is_overdue' => $this->rca->isOverdue(),
            ]),
            'rca_required' => $this->severity->isHigh(),
            'links' => IncidentLinkResource::collection($this->whenLoaded('links')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'watchers' => $this->whenLoaded('watchers', fn () => $this->watchers->map(fn (User $user) => $this->summary($user))),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function summary(?object $model): ?array
    {
        return $model === null ? null : ['id' => $model->id, 'name' => $model->name];
    }

    private function secondsInCurrentStatus(): ?int
    {
        $lastChange = $this->resource->getAttributes()['status_history_max_changed_at'] ?? null;

        return $lastChange === null ? null : (int) Carbon::parse($lastChange)->diffInSeconds(now());
    }

    private function workMinutes(): ?int
    {
        $attributes = $this->resource->getAttributes();

        return array_key_exists('time_entries_sum_minutes', $attributes) ? (int) $attributes['time_entries_sum_minutes'] : null;
    }
}
