<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Actions\Incidents\SaveResolution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\UpdateResolutionRequest;
use App\Http\Resources\IncidentResolutionResource;
use App\Models\Incident;
use Illuminate\Support\Facades\Gate;

/**
 * Structured fix documentation (specification section 7).
 */
class IncidentResolutionController extends Controller
{
    public function show(Incident $incident): IncidentResolutionResource
    {
        Gate::authorize('view', $incident);

        return new IncidentResolutionResource($incident->resolution()->firstOrFail());
    }

    public function update(UpdateResolutionRequest $request, Incident $incident, SaveResolution $saveResolution): IncidentResolutionResource
    {
        Gate::authorize('writeResolution', $incident);

        return new IncidentResolutionResource($saveResolution->handle($incident, $request->user(), $request->validated()));
    }
}
