<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SprintState;
use App\Enums\TaskStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\CompleteSprintRequest;
use App\Http\Requests\Projects\StartSprintRequest;
use App\Http\Requests\Projects\StoreSprintRequest;
use App\Http\Requests\Projects\UpdateSprintRequest;
use App\Http\Resources\SprintResource;
use App\Models\Project;
use App\Models\Sprint;
use App\Services\SprintService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Sprint CRUD and lifecycle. Completing a sprint sends unfinished work to the backlog or
 * another planned sprint; deleting planned sprints returns their tasks to the backlog.
 */
class SprintController extends Controller
{
    public function __construct(private SprintService $sprintService) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return SprintResource::collection(
            $project->sprints()
                ->withCount([
                    'tasks',
                    'tasks as done_tasks_count' => fn (Builder $query) => $query->where('status', TaskStatus::Done->value),
                ])
                ->orderByRaw("FIELD(state, 'active', 'planned', 'completed')")
                ->orderBy('starts_on')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreSprintRequest $request, Project $project): JsonResponse
    {
        Gate::authorize('manageSprints', $project);

        $sprint = $project->sprints()->create($request->validated());

        return (new SprintResource($sprint))->response()->setStatusCode(201);
    }

    public function show(Sprint $sprint): SprintResource
    {
        Gate::authorize('view', Project::query()->findOrFail($sprint->project_id));

        $sprint->loadCount([
            'tasks',
            'tasks as done_tasks_count' => fn (Builder $query) => $query->where('status', TaskStatus::Done->value),
        ]);

        return new SprintResource($sprint);
    }

    public function update(UpdateSprintRequest $request, Sprint $sprint): SprintResource
    {
        $project = Project::query()->findOrFail($sprint->project_id);
        Gate::authorize('manageSprints', $project);

        if ($sprint->isCompleted()) {
            throw new ApiException('A completed sprint cannot be changed.', 'sprint_completed', 409);
        }

        $sprint->update($request->validated());

        return new SprintResource($sprint->refresh());
    }

    public function start(StartSprintRequest $request, Sprint $sprint): SprintResource
    {
        $project = Project::query()->findOrFail($sprint->project_id);
        Gate::authorize('manageSprints', $project);

        $sprint = $this->sprintService->start($sprint, $request->user(), $request->validated());

        return new SprintResource($sprint);
    }

    public function complete(CompleteSprintRequest $request, Sprint $sprint): SprintResource
    {
        $project = Project::query()->findOrFail($sprint->project_id);
        Gate::authorize('manageSprints', $project);

        $sprint = $this->sprintService->complete($sprint, $request->user(), $request->validated());

        return new SprintResource($sprint);
    }

    /**
     * Only planned sprints can be deleted; deleting one returns its tasks to the backlog
     * through the foreign key's nullOnDelete.
     */
    public function destroy(Sprint $sprint): Response
    {
        $project = Project::query()->findOrFail($sprint->project_id);
        Gate::authorize('manageSprints', $project);

        if ($sprint->state !== SprintState::Planned) {
            throw new ApiException('Only a planned sprint can be deleted.', 'sprint_not_planned', 409);
        }

        $sprint->delete();

        return response()->noContent();
    }
}
