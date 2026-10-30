<?php

namespace App\Actions\Incidents;

use App\Models\Incident;
use App\Models\IncidentResolution;
use App\Models\User;

/**
 * Create or update the structured fix documentation of an incident.
 */
class SaveResolution
{
    /**
     * @param  array<string, mixed>  $data  validated resolution fields
     */
    public function handle(Incident $incident, ?User $editor, array $data): IncidentResolution
    {
        $resolution = $incident->resolution()->updateOrCreate([], [...$data, 'updated_by' => $editor?->id]);

        $incident->setRelation('resolution', $resolution);

        return $resolution;
    }
}
