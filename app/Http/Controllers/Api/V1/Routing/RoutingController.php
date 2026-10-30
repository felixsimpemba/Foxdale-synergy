<?php

namespace App\Http\Controllers\Api\V1\Routing;

use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Routing\RoutingQueryRequest;
use App\Http\Resources\RoutingDecisionResource;
use App\Models\RoutingRule;
use App\Models\Service;
use App\Services\RoutingService;
use Illuminate\Support\Facades\Gate;

class RoutingController extends Controller
{
    public function __construct(private RoutingService $routing) {}

    /**
     * GET /routing/who-to-report: shown in the reporting form before submitting, so the
     * reporter sees which team, on-call person and escalation contacts will be involved.
     */
    public function whoToReport(RoutingQueryRequest $request): RoutingDecisionResource
    {
        return $this->decide($request);
    }

    /**
     * POST /routing-rules/test: the same answer, for people editing the rules.
     */
    public function simulate(RoutingQueryRequest $request): RoutingDecisionResource
    {
        Gate::authorize('simulate', RoutingRule::class);

        return $this->decide($request);
    }

    private function decide(RoutingQueryRequest $request): RoutingDecisionResource
    {
        $severity = Severity::from((int) $request->validated('severity'));
        $service = $request->filled('service_id') ? Service::with('ownerTeam')->find($request->integer('service_id')) : null;
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;

        return new RoutingDecisionResource($this->routing->decide($service, $categoryId, $severity), $severity);
    }
}
