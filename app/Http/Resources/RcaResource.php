<?php

namespace App\Http\Resources;

use App\Enums\RcaStatus;
use App\Models\Rca;
use App\Models\RcaTimelineEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An RCA with its timeline and action items (when loaded). "missing" lists what still
 * blocks approval, so the form can show it before the author sends it for review.
 *
 * @mixin Rca
 */
class RcaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incident_id,
            'incident' => $this->whenLoaded('incident', fn () => [
                'id' => $this->incident->id,
                'ref' => $this->incident->ref,
                'title' => $this->incident->title,
                'severity' => $this->incident->severity->value,
                'status' => $this->incident->status,
                'service' => $this->incident->relationLoaded('service') && $this->incident->service !== null
                    ? ['id' => $this->incident->service->id, 'name' => $this->incident->service->name]
                    : null,
                'resolved_at' => $this->incident->resolved_at?->toIso8601ZuluString(),
            ]),
            'status' => $this->status,
            'summary' => $this->summary,
            'impact' => $this->impact,
            'detection' => $this->detection,
            'root_cause' => $this->root_cause,
            'root_cause_category' => $this->root_cause_category,
            'contributing_factors' => $this->contributing_factors ?? [],
            'five_whys' => $this->five_whys ?? [],
            'went_well' => $this->went_well ?? [],
            'went_wrong' => $this->went_wrong ?? [],
            'lessons_learned' => $this->lessons_learned,
            'recurrence_risk' => $this->recurrence_risk,
            'author' => $this->whenLoaded('author', fn () => $this->person($this->author)),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->person($this->reviewer)),
            'due_at' => $this->due_at?->toIso8601ZuluString(),
            'is_overdue' => $this->isOverdue(),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'rejected_at' => $this->rejected_at?->toIso8601ZuluString(),
            'rejection_reason' => $this->rejection_reason,
            'timeline' => $this->whenLoaded('timelineEvents', fn () => $this->timelineEvents->map(fn (RcaTimelineEvent $event): array => [
                'id' => $event->id,
                'at' => $event->at->toIso8601ZuluString(),
                'event' => $event->event,
                'actor' => $event->actor,
            ])),
            'action_items' => RcaActionItemResource::collection($this->whenLoaded('actionItems')),
            'action_items_count' => $this->whenCounted('actionItems'),
            'missing' => $this->when(
                $this->relationLoaded('actionItems') && $this->status !== RcaStatus::Approved,
                fn () => $this->approvalProblems(),
            ),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
