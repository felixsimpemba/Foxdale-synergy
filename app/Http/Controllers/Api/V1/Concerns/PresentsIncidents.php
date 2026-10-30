<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Resources\IncidentResource;
use App\Models\Incident;

trait PresentsIncidents
{
    /**
     * Relations every incident response includes.
     *
     * @var list<string>
     */
    protected array $incidentRelations = ['service', 'category', 'reporter', 'team', 'assignee'];

    /**
     * Reload the incident with its summary relations and timings for the response.
     *
     * @param  list<string>  $extraRelations
     */
    protected function presentIncident(Incident $incident, array $extraRelations = []): IncidentResource
    {
        $fresh = Incident::query()
            ->withTimings()
            ->with([...$this->incidentRelations, ...$extraRelations])
            ->findOrFail($incident->id);

        return new IncidentResource($fresh);
    }
}
