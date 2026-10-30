<?php

namespace App\Http\Resources;

use App\Models\Incident;
use App\Models\RcaActionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A past incident and how it was fixed. Leaves out the incident description and people,
 * since published entries are readable by users who cannot open the incident itself.
 *
 * @mixin Incident
 */
class KnowledgeBaseEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolution = $this->resolution;

        return [
            'incident' => [
                'id' => $this->id,
                'ref' => $this->ref,
                'title' => $this->title,
                'severity' => $this->severity->value,
                'status' => $this->status,
                'service' => $this->service === null ? null : ['id' => $this->service->id, 'name' => $this->service->name],
                'category' => $this->category === null ? null : ['id' => $this->category->id, 'name' => $this->category->name],
                'reported_at' => $this->reported_at->toIso8601ZuluString(),
                'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            ],
            'resolution' => [
                'summary' => $resolution->summary,
                'workaround' => $resolution->workaround,
                'permanent_fix' => $resolution->permanent_fix,
                'fix_type' => $resolution->fix_type,
                'steps' => $resolution->steps ?? [],
                'linked_prs' => $resolution->linked_prs ?? [],
                'verification' => $resolution->verification,
                'deployed_at' => $resolution->deployed_at?->toIso8601ZuluString(),
            ],
            // The approved root cause analysis, when there is one; action items only on the entry page.
            'rca' => $this->whenLoaded('approvedRca', fn () => $this->approvedRca === null ? null : [
                'summary' => $this->approvedRca->summary,
                'root_cause' => $this->approvedRca->root_cause,
                'root_cause_category' => $this->approvedRca->root_cause_category,
                'contributing_factors' => $this->approvedRca->contributing_factors ?? [],
                'five_whys' => $this->approvedRca->five_whys ?? [],
                'lessons_learned' => $this->approvedRca->lessons_learned,
                'recurrence_risk' => $this->approvedRca->recurrence_risk,
                'approved_at' => $this->approvedRca->approved_at?->toIso8601ZuluString(),
                'action_items' => $this->when(
                    $this->approvedRca->relationLoaded('actionItems'),
                    fn () => $this->approvedRca->actionItems->map(fn (RcaActionItem $item): array => [
                        'type' => $item->type,
                        'title' => $item->title,
                        'status' => $item->status,
                    ]),
                ),
            ]),
            'is_published' => $resolution->is_published,
            'published_at' => $resolution->published_at?->toIso8601ZuluString(),
            'published_by' => $resolution->relationLoaded('publisher') && $resolution->publisher !== null
                ? ['id' => $resolution->publisher->id, 'name' => $resolution->publisher->name]
                : null,
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
