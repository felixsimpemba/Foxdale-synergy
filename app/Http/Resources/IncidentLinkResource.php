<?php

namespace App\Http\Resources;

use App\Models\IncidentLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IncidentLink
 */
class IncidentLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'related_incident_id' => $this->related_incident_id,
            'related_incident' => $this->whenLoaded('relatedIncident', fn () => [
                'id' => $this->relatedIncident->id,
                'ref' => $this->relatedIncident->ref,
                'title' => $this->relatedIncident->title,
                'status' => $this->relatedIncident->status,
            ]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
