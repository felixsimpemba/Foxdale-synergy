<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSlaPolicyRequest;
use App\Http\Resources\SlaPolicyResource;
use App\Models\SlaPolicy;
use App\Services\SlaService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * SLA targets per severity. Changes apply to incidents reported, or re-graded, afterwards.
 */
class SlaPolicyController extends Controller
{
    public function __construct(private SlaService $sla) {}

    public function index(): AnonymousResourceCollection
    {
        return SlaPolicyResource::collection(array_map(
            fn (Severity $severity): array => ['severity' => $severity->value, ...$this->sla->targetsFor($severity)],
            Severity::cases(),
        ));
    }

    public function update(UpdateSlaPolicyRequest $request, int $severity): SlaPolicyResource
    {
        Gate::authorize('update', SlaPolicy::class);

        $level = Severity::tryFrom($severity) ?? abort(404);

        SlaPolicy::updateOrCreate(['severity' => $level], $request->validated());
        $this->sla->forgetCachedPolicies();

        return new SlaPolicyResource(['severity' => $level->value, ...$this->sla->targetsFor($level)]);
    }
}
