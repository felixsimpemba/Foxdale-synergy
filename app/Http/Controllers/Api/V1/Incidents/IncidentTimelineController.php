<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Resources\StatusHistoryResource;
use App\Models\Incident;
use App\Services\IncidentTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class IncidentTimelineController extends Controller
{
    public function __construct(private IncidentTimelineService $timeline) {}

    /**
     * Merged feed of updates, status changes, assignments and time entries, oldest first.
     */
    public function timeline(Incident $incident): JsonResponse
    {
        Gate::authorize('view', $incident);

        return response()->json(['data' => $this->timeline->timeline($incident)]);
    }

    /**
     * Status history with the seconds spent in each status.
     */
    public function history(Incident $incident): JsonResponse
    {
        Gate::authorize('view', $incident);

        $history = $incident->statusHistory()->with('user')->orderBy('changed_at')->orderBy('id')->get();

        return response()->json([
            'data' => StatusHistoryResource::collection($history),
            'seconds_in_status' => (object) $this->timeline->secondsPerStatus($incident),
        ]);
    }
}
