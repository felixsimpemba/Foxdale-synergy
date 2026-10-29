<?php

namespace App\Http\Controllers\Api\V1\Routing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Routing\StoreEscalationPolicyRequest;
use App\Http\Resources\EscalationPolicyResource;
use App\Models\EscalationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EscalationPolicyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', EscalationPolicy::class);

        return EscalationPolicyResource::collection(EscalationPolicy::query()->with('levels')->orderBy('name')->get());
    }

    public function store(StoreEscalationPolicyRequest $request): JsonResponse
    {
        Gate::authorize('create', EscalationPolicy::class);

        $policy = DB::transaction(function () use ($request): EscalationPolicy {
            $policy = EscalationPolicy::create($request->safe()->only('name', 'description'));
            $policy->levels()->createMany($request->validated('levels'));

            return $policy;
        });

        return (new EscalationPolicyResource($policy->load('levels')))->response()->setStatusCode(201);
    }

    public function show(EscalationPolicy $escalationPolicy): EscalationPolicyResource
    {
        Gate::authorize('view', $escalationPolicy);

        return new EscalationPolicyResource($escalationPolicy->load('levels'));
    }

    /**
     * When levels are sent they replace the existing levels.
     */
    public function update(StoreEscalationPolicyRequest $request, EscalationPolicy $escalationPolicy): EscalationPolicyResource
    {
        Gate::authorize('update', $escalationPolicy);

        DB::transaction(function () use ($request, $escalationPolicy): void {
            $escalationPolicy->update($request->safe()->only('name', 'description'));

            if ($request->has('levels')) {
                $escalationPolicy->levels()->delete();
                $escalationPolicy->levels()->createMany($request->validated('levels'));
            }
        });

        return new EscalationPolicyResource($escalationPolicy->load('levels'));
    }

    /**
     * Rules and incidents using the policy keep working without escalation.
     */
    public function destroy(EscalationPolicy $escalationPolicy): Response
    {
        Gate::authorize('delete', $escalationPolicy);

        $escalationPolicy->delete();

        return response()->noContent();
    }
}
