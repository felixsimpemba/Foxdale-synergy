<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreServiceRequest;
use App\Http\Requests\Catalog\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Service catalog. Services are deactivated (is_active=false) rather than deleted, because
 * incidents keep referring to them.
 */
class ServiceController extends Controller
{
    /**
     * Filters: q, owner_team_id, is_active.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Service::class);

        $services = Service::query()
            ->with('ownerTeam')
            ->when($request->filled('q'), fn (Builder $query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('owner_team_id'), fn (Builder $query) => $query->where('owner_team_id', $request->integer('owner_team_id')))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request, 100));

        return ServiceResource::collection($services);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        Gate::authorize('create', Service::class);

        $service = Service::create($request->validated());

        return (new ServiceResource($service->load('ownerTeam')))->response()->setStatusCode(201);
    }

    public function show(Service $service): ServiceResource
    {
        Gate::authorize('view', $service);

        return new ServiceResource($service->load('ownerTeam'));
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        Gate::authorize('update', $service);

        $service->update($request->validated());

        return new ServiceResource($service->load('ownerTeam'));
    }
}
