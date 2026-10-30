<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\StoreTimeEntryRequest;
use App\Http\Requests\Incidents\UpdateTimeEntryRequest;
use App\Http\Resources\TimeEntryResource;
use App\Models\Incident;
use App\Models\TimeEntry;
use App\Services\TimeTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class TimeEntryController extends Controller
{
    public function __construct(private TimeTrackingService $timeTracking) {}

    public function index(Incident $incident): AnonymousResourceCollection
    {
        Gate::authorize('view', $incident);

        return TimeEntryResource::collection(
            $incident->timeEntries()->with('user')->orderByDesc('worked_on')->orderByDesc('id')->get(),
        );
    }

    public function store(StoreTimeEntryRequest $request, Incident $incident): JsonResponse
    {
        Gate::authorize('logTime', $incident);

        $entry = $this->timeTracking->log($incident, $request->user(), $request->validated());

        return (new TimeEntryResource($entry->load('user')))->response()->setStatusCode(201);
    }

    public function update(UpdateTimeEntryRequest $request, TimeEntry $timeEntry): TimeEntryResource
    {
        Gate::authorize('update', $timeEntry);

        $timeEntry->update($request->validated());

        return new TimeEntryResource($timeEntry->load('user'));
    }

    public function destroy(TimeEntry $timeEntry): Response
    {
        Gate::authorize('delete', $timeEntry);

        $timeEntry->delete();

        return response()->noContent();
    }

    public function startTimer(Request $request, Incident $incident): JsonResponse
    {
        Gate::authorize('logTime', $incident);

        $entry = $this->timeTracking->startTimer($incident, $request->user());

        return (new TimeEntryResource($entry->load('user')))->response()->setStatusCode(201);
    }

    public function stopTimer(Request $request, Incident $incident): TimeEntryResource
    {
        Gate::authorize('logTime', $incident);

        return new TimeEntryResource($this->timeTracking->stopTimer($incident, $request->user())->load('user'));
    }
}
