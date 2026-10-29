<?php

namespace App\Http\Resources;

use App\Models\IncidentResolution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IncidentResolution
 */
class IncidentResolutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'incident_id' => $this->incident_id,
            'summary' => $this->summary,
            'workaround' => $this->workaround,
            'permanent_fix' => $this->permanent_fix,
            'fix_type' => $this->fix_type,
            'steps' => $this->steps ?? [],
            'linked_prs' => $this->linked_prs ?? [],
            'deployed_at' => $this->deployed_at?->toIso8601ZuluString(),
            'verification' => $this->verification,
            'follow_up_needed' => (bool) $this->follow_up_needed,
            'is_published' => (bool) $this->is_published,
            'is_complete' => $this->isComplete(),
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
