<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Actions\Incidents\CreateIncident;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Http\Controllers\Api\V1\Concerns\PresentsIncidents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\DeleteIncidentRequest;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    use PresentsIncidents;

    /**
     * Sortable fields for ?sort=-severity,reported_at.
     */
    private const SORTABLE = ['severity', 'reported_at', 'status', 'updated_at', 'ref'];

    /**
     * Optional relations for ?include= on the detail endpoint. Unknown names are ignored.
     */
    private const INCLUDES = [
        'updates' => 'updates.user',
        'history' => 'statusHistory.user',
        'time' => 'timeEntries.user',
        'resolution' => 'resolution',
        'links' => 'links.relatedIncident',
        'watchers' => 'watchers',
        'documents' => 'documents.uploader',
        'rca' => 'rca',
    ];

    /**
     * Filters: status, severity (single, comma-separated or array), service_id, category_id,
     * team_id, assignee_id, reporter_id, source, from, to (reported_at), q (ref or title).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Incident::class);

        $filters = $request->merge([
            'status' => $this->listParam($request, 'status'),
            'severity' => $this->listParam($request, 'severity'),
        ])->validate([
            'status' => ['array'],
            'status.*' => [Rule::enum(IncidentStatus::class)],
            'severity' => ['array'],
            'severity.*' => [Rule::enum(Severity::class)],
            'service_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'reporter_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::enum(IncidentSource::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'regex:/^-?('.implode('|', self::SORTABLE).')(,-?('.implode('|', self::SORTABLE).'))*$/'],
        ]);

        $query = Incident::query()
            ->visibleTo($request->user())
            ->with($this->incidentRelations)
            ->withTimings()
            ->when($filters['status'] !== [], fn (Builder $query) => $query->whereIn('status', $filters['status']))
            ->when($filters['severity'] !== [], fn (Builder $query) => $query->whereIn('severity', $filters['severity']));

        foreach (['service_id', 'category_id', 'team_id', 'assignee_id', 'reporter_id', 'source'] as $column) {
            $query->when(filled($filters[$column] ?? null), fn (Builder $query) => $query->where($column, $filters[$column]));
        }

        $query
            ->when(filled($filters['from'] ?? null), fn (Builder $query) => $query->where('reported_at', '>=', $request->date('from')))
            ->when(filled($filters['to'] ?? null), fn (Builder $query) => $query->where('reported_at', '<=', $request->date('to')->endOfDay()))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('ref', $filters['q'])
                ->orWhere('title', 'like', '%'.addcslashes($filters['q'], '%_').'%')));

        $this->applySort($query, $filters['sort'] ?? '-reported_at');

        return IncidentResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(StoreIncidentRequest $request, CreateIncident $createIncident): JsonResponse
    {
        Gate::authorize('create', Incident::class);

        $incident = $createIncident->handle($request->user(), $request->validated());

        return $this->presentIncident($incident)->response()->setStatusCode(201);
    }

    /**
     * ?include=updates,history,time,resolution,links,watchers,documents,rca
     */
    public function show(Request $request, Incident $incident): IncidentResource
    {
        Gate::authorize('view', $incident);

        $includes = array_values(array_intersect_key(self::INCLUDES, array_flip($this->listParam($request, 'include'))));

        return $this->presentIncident($incident, $includes);
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): IncidentResource
    {
        Gate::authorize('update', $incident);

        $incident->update($request->validated());

        return $this->presentIncident($incident);
    }

    /**
     * Soft delete with a reason. Only incident managers hold incident.delete.
     */
    public function destroy(DeleteIncidentRequest $request, Incident $incident): Response
    {
        Gate::authorize('delete', $incident);

        $incident->forceFill(['deletion_reason' => $request->validated('reason')])->save();
        $incident->delete();

        return response()->noContent();
    }

    /**
     * @return list<string>
     */
    private function listParam(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), 'filled'));
        }

        return array_values(array_filter(explode(',', (string) $value), 'filled'));
    }

    /**
     * @param  Builder<Incident>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        foreach (explode(',', $sort) as $field) {
            $query->orderBy(ltrim($field, '-'), str_starts_with($field, '-') ? 'desc' : 'asc');
        }

        $query->orderByDesc('id');
    }
}
