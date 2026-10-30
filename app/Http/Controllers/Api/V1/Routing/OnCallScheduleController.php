<?php

namespace App\Http\Controllers\Api\V1\Routing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Routing\StoreOnCallScheduleRequest;
use App\Http\Requests\Routing\StoreOnCallShiftsRequest;
use App\Http\Resources\OnCallScheduleResource;
use App\Http\Resources\OnCallShiftResource;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OnCallScheduleController extends Controller
{
    /**
     * Filter: team_id.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', OnCallSchedule::class);

        return OnCallScheduleResource::collection(
            OnCallSchedule::query()
                ->with('team')
                ->when($request->filled('team_id'), fn (Builder $query) => $query->where('team_id', $request->integer('team_id')))
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreOnCallScheduleRequest $request): JsonResponse
    {
        Gate::authorize('create', OnCallSchedule::class);

        $schedule = OnCallSchedule::create($request->validated());

        return (new OnCallScheduleResource($schedule->load('team')))->response()->setStatusCode(201);
    }

    /**
     * The schedule with its current and upcoming shifts.
     */
    public function show(OnCallSchedule $onCallSchedule): OnCallScheduleResource
    {
        Gate::authorize('view', $onCallSchedule);

        return new OnCallScheduleResource($onCallSchedule->load([
            'team',
            'shifts' => fn ($query) => $query->where('ends_at', '>', now())->orderBy('starts_at')->with('user'),
        ]));
    }

    public function update(StoreOnCallScheduleRequest $request, OnCallSchedule $onCallSchedule): OnCallScheduleResource
    {
        Gate::authorize('update', $onCallSchedule);

        $onCallSchedule->update($request->validated());

        return new OnCallScheduleResource($onCallSchedule->load('team'));
    }

    public function destroy(OnCallSchedule $onCallSchedule): Response
    {
        Gate::authorize('delete', $onCallSchedule);

        $onCallSchedule->delete();

        return response()->noContent();
    }

    /**
     * Add shifts or overrides in one request.
     */
    public function storeShifts(StoreOnCallShiftsRequest $request, OnCallSchedule $onCallSchedule): JsonResponse
    {
        Gate::authorize('manageShifts', $onCallSchedule);

        $shifts = DB::transaction(fn () => $request->safe()->collect('shifts')
            ->map(fn (array $shift) => $onCallSchedule->shifts()->create($shift)));

        return OnCallShiftResource::collection($shifts->each->load('user'))->response()->setStatusCode(201);
    }

    public function destroyShift(OnCallSchedule $onCallSchedule, OnCallShift $shift): Response
    {
        Gate::authorize('manageShifts', $onCallSchedule);

        abort_unless($shift->on_call_schedule_id === $onCallSchedule->id, 404);

        $shift->delete();

        return response()->noContent();
    }
}
