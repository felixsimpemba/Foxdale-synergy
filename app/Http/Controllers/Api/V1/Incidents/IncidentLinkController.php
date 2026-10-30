<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\StoreIncidentLinkRequest;
use App\Http\Resources\IncidentLinkResource;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class IncidentLinkController extends Controller
{
    /**
     * Link to another incident the user can see: duplicate, related or caused_by.
     */
    public function store(StoreIncidentLinkRequest $request, Incident $incident): JsonResponse
    {
        Gate::authorize('update', $incident);

        $relatedId = $request->integer('related_incident_id');

        if ($relatedId === $incident->id) {
            throw ValidationException::withMessages(['related_incident_id' => ['An incident cannot be linked to itself.']]);
        }

        if (! Incident::query()->visibleTo($request->user())->whereKey($relatedId)->exists()) {
            throw ValidationException::withMessages(['related_incident_id' => ['The selected related incident id is invalid.']]);
        }

        $link = $incident->links()->firstOrCreate(
            ['related_incident_id' => $relatedId, 'type' => $request->validated('type')],
            ['created_by' => $request->user()->id],
        );

        return (new IncidentLinkResource($link->load('relatedIncident')))
            ->response()
            ->setStatusCode($link->wasRecentlyCreated ? 201 : 200);
    }
}
