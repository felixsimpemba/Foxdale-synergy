<?php

namespace App\Http\Controllers\Api\V1\Routing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Routing\StoreRoutingRuleRequest;
use App\Http\Resources\RoutingRuleResource;
use App\Models\RoutingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RoutingRuleController extends Controller
{
    /**
     * Rules in evaluation order.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', RoutingRule::class);

        return RoutingRuleResource::collection(RoutingRule::query()->orderBy('priority')->orderBy('id')->get());
    }

    public function store(StoreRoutingRuleRequest $request): JsonResponse
    {
        Gate::authorize('create', RoutingRule::class);

        return (new RoutingRuleResource(RoutingRule::create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(StoreRoutingRuleRequest $request, RoutingRule $routingRule): RoutingRuleResource
    {
        Gate::authorize('update', $routingRule);

        $routingRule->update($request->validated());

        return new RoutingRuleResource($routingRule);
    }

    public function destroy(RoutingRule $routingRule): Response
    {
        Gate::authorize('delete', $routingRule);

        $routingRule->delete();

        return response()->noContent();
    }
}
