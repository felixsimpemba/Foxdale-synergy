<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Subscribe or unsubscribe the signed-in user to an incident's updates.
 */
class IncidentWatcherController extends Controller
{
    public function store(Request $request, Incident $incident): JsonResponse
    {
        Gate::authorize('view', $incident);

        $incident->watchers()->syncWithoutDetaching([$request->user()->id]);

        return response()->json(['watching' => true]);
    }

    public function destroy(Request $request, Incident $incident): JsonResponse
    {
        Gate::authorize('view', $incident);

        $incident->watchers()->detach($request->user()->id);

        return response()->json(['watching' => false]);
    }
}
