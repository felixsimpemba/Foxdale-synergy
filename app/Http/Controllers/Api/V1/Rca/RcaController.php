<?php

namespace App\Http\Controllers\Api\V1\Rca;

use App\Enums\RcaStatus;
use App\Enums\RootCauseCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rca\RejectRcaRequest;
use App\Http\Requests\Rca\SaveRcaRequest;
use App\Http\Resources\RcaResource;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\User;
use App\Services\RcaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Root cause analysis (specification section 8).
 */
class RcaController extends Controller
{
    /**
     * Relations every full RCA response includes.
     *
     * @var list<string>
     */
    private const RELATIONS = [
        'incident.service', 'author', 'reviewer', 'timelineEvents', 'actionItems.owner', 'actionItems.completer',
    ];

    public function __construct(private RcaService $rcas) {}

    /**
     * Filters: status (comma-separated), service_id, root_cause_category, author_id, overdue,
     * from and to (created). meta.counts has the totals per status and overdue, for tabs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Rca::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'service_id' => ['nullable', 'integer'],
            'root_cause_category' => ['nullable', Rule::enum(RootCauseCategory::class)],
            'author_id' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $statuses = array_values(array_intersect(
            explode(',', (string) ($filters['status'] ?? '')),
            array_column(RcaStatus::cases(), 'value'),
        ));

        $query = $this->visible($request->user())
            ->with(['incident.service', 'author', 'reviewer'])
            ->withCount('actionItems')
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->when(filled($filters['service_id'] ?? null), fn (Builder $query) => $query->whereHas('incident', fn (Builder $incident) => $incident->where('service_id', $filters['service_id'])))
            ->when(filled($filters['root_cause_category'] ?? null), fn (Builder $query) => $query->where('root_cause_category', $filters['root_cause_category']))
            ->when(filled($filters['author_id'] ?? null), fn (Builder $query) => $query->where('author_id', $filters['author_id']))
            ->when($request->boolean('overdue'), fn (Builder $query) => $this->overdue($query))
            ->when(filled($filters['from'] ?? null), fn (Builder $query) => $query->where('created_at', '>=', $request->date('from')))
            ->when(filled($filters['to'] ?? null), fn (Builder $query) => $query->where('created_at', '<=', $request->date('to')->endOfDay()))
            // Waiting for review first, then by due date.
            ->orderByRaw("case status when 'in_review' then 0 when 'rejected' then 1 when 'draft' then 2 else 3 end")
            ->orderByRaw('due_at is null, due_at')
            ->orderByDesc('id');

        $counts = $this->visible($request->user())->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return RcaResource::collection($query->paginate($this->perPage($request)))->additional(['meta' => ['counts' => [
            ...collect(RcaStatus::cases())->mapWithKeys(fn (RcaStatus $status): array => [$status->value => (int) ($counts[$status->value] ?? 0)])->all(),
            'overdue' => $this->overdue($this->visible($request->user()))->count(),
        ]]]);
    }

    /**
     * The RCA of an incident. 404 when there is none yet.
     */
    public function showForIncident(Incident $incident): RcaResource
    {
        Gate::authorize('view', $incident);

        return new RcaResource(Rca::query()->where('incident_id', $incident->id)->with(self::RELATIONS)->firstOrFail());
    }

    public function store(SaveRcaRequest $request, Incident $incident): JsonResponse
    {
        Gate::authorize('create', [Rca::class, $incident]);

        $rca = $this->rcas->create($incident, $request->user(), $request->validated());

        return $this->present($rca)->response()->setStatusCode(201);
    }

    public function show(Rca $rca): RcaResource
    {
        Gate::authorize('view', $rca);

        return $this->present($rca);
    }

    public function update(SaveRcaRequest $request, Rca $rca): RcaResource
    {
        Gate::authorize('update', $rca);

        return $this->present($this->rcas->update($rca, $request->validated()));
    }

    public function submit(Request $request, Rca $rca): RcaResource
    {
        Gate::authorize('update', $rca);

        return $this->present($this->rcas->submit($rca, $request->user()));
    }

    public function approve(Request $request, Rca $rca): RcaResource
    {
        Gate::authorize('review', $rca);

        return $this->present($this->rcas->approve($rca, $request->user()));
    }

    public function reject(RejectRcaRequest $request, Rca $rca): RcaResource
    {
        Gate::authorize('review', $rca);

        return $this->present($this->rcas->reject($rca, $request->user(), $request->validated('reason')));
    }

    private function present(Rca $rca): RcaResource
    {
        return new RcaResource(Rca::query()->with(self::RELATIONS)->findOrFail($rca->id));
    }

    /**
     * @return Builder<Rca>
     */
    private function visible(User $user): Builder
    {
        return Rca::query()->whereHas('incident', fn (Builder $incident) => $incident->visibleTo($user));
    }

    /**
     * @param  Builder<Rca>  $query
     * @return Builder<Rca>
     */
    private function overdue(Builder $query): Builder
    {
        return $query->where('status', '!=', RcaStatus::Approved->value)->where('due_at', '<', now());
    }
}
