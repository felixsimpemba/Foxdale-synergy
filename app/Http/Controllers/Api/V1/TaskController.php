<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ApproveTaskRequest;
use App\Http\Requests\Projects\AssignTaskRequest;
use App\Http\Requests\Projects\MoveTaskRequest;
use App\Http\Requests\Projects\RequestTaskChangesRequest;
use App\Http\Requests\Projects\RequestTaskReviewRequest;
use App\Http\Requests\Projects\StoreTaskRequest;
use App\Http\Requests\Projects\UpdateTaskRequest;
use App\Http\Resources\SprintResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TaskTimelineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tickets: list, board, CRUD and the workflow actions. Status only changes through /move so
 * board ordering and activity entries always have one owner.
 */
class TaskController extends Controller
{
    /**
     * Optional relations for list and detail responses.
     */
    private const RELATIONS = ['project', 'sprint', 'parent', 'assignee', 'reporter', 'reviewer'];

    /**
     * The board response already carries the project, so its cards skip that relation.
     */
    private const BOARD_RELATIONS = ['sprint', 'parent', 'assignee', 'reporter', 'reviewer'];

    public function __construct(
        private TaskService $tasks,
        private TaskTimelineService $taskTimeline,
    ) {}

    /**
     * Filters: status (comma-separated), type, priority, assignee_id, reviewer_id, parent_id,
     * sprint_id (an id or "backlog"), q (ref or title), sort.
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [Task::class, $project]);

        $filters = $request->merge([
            'status' => $this->listParam($request, 'status'),
        ])->validate([
            'status' => ['array'],
            'status.*' => [Rule::enum(TaskStatus::class)],
            'type' => ['nullable', Rule::enum(TaskType::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['nullable', 'integer'],
            'reviewer_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'sprint_id' => ['nullable', 'string', 'regex:/^(backlog|\d+)$/'],
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'regex:/^-?(position|due_date|updated_at|ref|title)(,-?(position|due_date|updated_at|ref|title))*$/'],
        ]);

        $query = Task::query()
            ->where('project_id', $project->id)
            ->with(self::RELATIONS)
            ->withCount($this->subtaskCounts())
            ->when($filters['status'] !== [], fn (Builder $query) => $query->whereIn('status', $filters['status']));

        foreach (['type', 'priority', 'assignee_id', 'reviewer_id', 'parent_id'] as $column) {
            $query->when(filled($filters[$column] ?? null), fn (Builder $query) => $query->where($column, $filters[$column]));
        }

        $query
            ->when(($filters['sprint_id'] ?? null) === 'backlog', fn (Builder $query) => $query->whereNull('sprint_id'))
            ->when(ctype_digit((string) ($filters['sprint_id'] ?? '')), fn (Builder $query) => $query->where('sprint_id', (int) $filters['sprint_id']))
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('ref', 'like', '%'.addcslashes($filters['q'], '%_').'%')
                ->orWhere('title', 'like', '%'.addcslashes($filters['q'], '%_').'%')));

        $this->applySort($query, $filters['sort'] ?? 'position');

        return TaskResource::collection($query->paginate($this->perPage($request, 50)));
    }

    /**
     * The Kanban columns. ?sprint_id=backlog, a sprint id, or omitted for the active sprint
     * (falling back to the backlog when none is active).
     */
    public function board(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [Task::class, $project]);

        $sprint = $this->resolveSprint($request, $project);

        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->whereNull('parent_id')
            ->when(
                $sprint === null,
                fn (Builder $query) => $query->whereNull('sprint_id'),
                fn (Builder $query) => $query->where('sprint_id', $sprint->id),
            )
            ->with(self::BOARD_RELATIONS)
            ->withCount($this->subtaskCounts())
            ->orderBy('position')
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($sprint !== null) {
            $sprint->loadCount([
                'tasks',
                'tasks as done_tasks' => fn (Builder $query) => $query->where('status', TaskStatus::Done->value),
            ]);
        }

        $columns = [];

        foreach (TaskStatus::cases() as $status) {
            $column = $tasks->filter(fn (Task $task): bool => $task->status === $status)->values();

            $columns[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => $column->count(),
                'tasks' => TaskResource::collection($column),
            ];
        }

        return response()->json(['data' => [
            'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name],
            'sprint' => $sprint === null ? null : new SprintResource($sprint),
            'columns' => $columns,
        ]]);
    }

    public function store(StoreTaskRequest $request, Project $project): JsonResponse
    {
        Gate::authorize('create', [Task::class, $project]);

        $task = $this->tasks->create($project, $request->user(), $request->validated());

        return (new TaskResource($this->present($task)))->response()->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return new TaskResource($this->present($task, withSubtasks: true));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        Gate::authorize('update', $task);

        $task = $this->tasks->update($task, $request->user(), $request->validated());

        return new TaskResource($this->present($task));
    }

    public function destroy(Task $task): Response
    {
        Gate::authorize('delete', $task);

        $this->tasks->delete($task);

        return response()->noContent();
    }

    public function move(MoveTaskRequest $request, Task $task): TaskResource
    {
        Gate::authorize('update', $task);

        $task = $this->tasks->move($task, $request->user(), $request->validated());

        return new TaskResource($this->present($task));
    }

    public function assign(AssignTaskRequest $request, Task $task): TaskResource
    {
        Gate::authorize('assign', $task);

        $assignee = $request->validated('assignee_id') === null
            ? null
            : User::query()->findOrFail($request->integer('assignee_id'));

        $task = $this->tasks->assign($task, $request->user(), $assignee);

        return new TaskResource($this->present($task));
    }

    public function requestReview(RequestTaskReviewRequest $request, Task $task): TaskResource
    {
        Gate::authorize('update', $task);

        $reviewer = User::query()->findOrFail($request->integer('reviewer_id'));
        $task = $this->tasks->requestReview($task, $request->user(), $reviewer);

        return new TaskResource($this->present($task));
    }

    public function approve(ApproveTaskRequest $request, Task $task): TaskResource
    {
        Gate::authorize('review', $task);

        $task = $this->tasks->approve($task, $request->user(), $request->validated('note'));

        return new TaskResource($this->present($task));
    }

    public function requestChanges(RequestTaskChangesRequest $request, Task $task): TaskResource
    {
        Gate::authorize('review', $task);

        $task = $this->tasks->requestChanges($task, $request->user(), $request->validated('note'));

        return new TaskResource($this->present($task));
    }

    public function timeline(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        return response()->json(['data' => $this->taskTimeline->timeline($task)]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function subtaskCounts(): array
    {
        return [
            'subtasks',
            'subtasks as done_subtasks_count' => fn (Builder $query) => $query->where('status', TaskStatus::Done->value),
        ];
    }

    private function present(Task $task, bool $withSubtasks = false): Task
    {
        $relations = self::RELATIONS;

        if ($withSubtasks) {
            $relations[] = 'subtasks.assignee';
        }

        $task->load($relations);
        $task->loadCount($this->subtaskCounts());

        return $task;
    }

    private function resolveSprint(Request $request, Project $project): ?Sprint
    {
        $raw = $request->query('sprint_id');

        if ($raw === 'backlog') {
            return null;
        }

        if (filled($raw)) {
            if (! ctype_digit((string) $raw)) {
                throw ValidationException::withMessages(['sprint_id' => 'Choose a sprint or backlog.']);
            }

            $sprint = Sprint::query()->whereKey((int) $raw)->where('project_id', $project->id)->first();

            if ($sprint === null) {
                throw ValidationException::withMessages(['sprint_id' => 'That sprint is not part of this project.']);
            }

            return $sprint;
        }

        return $project->activeSprint()->first();
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
     * @param  Builder<Task>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        foreach (explode(',', $sort) as $field) {
            $descending = str_starts_with($field, '-');
            $column = ltrim($field, '-');

            $query->orderBy($column, $descending ? 'desc' : 'asc');
        }

        $query->orderBy('id');
    }
}
