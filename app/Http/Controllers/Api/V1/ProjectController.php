<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectMemberRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Projects and their members. Archived projects stay readable but drop out of the default
 * list; deleting a project archives it.
 */
class ProjectController extends Controller
{
    public function __construct(private ProjectService $projects) {}

    /**
     * Filters: q, status, mine (member or lead), include_archived.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'mine' => ['sometimes', 'boolean'],
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        $query = Project::query()
            ->visibleTo($request->user())
            ->with(['lead', 'ownerTeam', 'activeSprint', 'members'])
            ->withCount([
                'members',
                'tasks',
                'tasks as open_tasks_count' => fn (Builder $query) => $query->whereIn('status', TaskStatus::openValues()),
            ])
            ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.addcslashes($filters['q'], '%_').'%')
                ->orWhere('key', 'like', '%'.addcslashes($filters['q'], '%_').'%')))
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            // Archived projects are opt-in unless the caller asks for that status explicitly.
            ->when(
                ! ($filters['include_archived'] ?? false) && blank($filters['status'] ?? null),
                fn (Builder $query) => $query->where('status', '!=', ProjectStatus::Archived->value),
            )
            ->when($request->boolean('mine'), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('lead_id', $request->user()->id)
                ->orWhereHas('members', fn (Builder $members) => $members->whereKey($request->user()->id))))
            ->orderBy('name')
            ->orderBy('id');

        return ProjectResource::collection($query->paginate($this->perPage($request, 50)));
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        Gate::authorize('create', Project::class);

        $project = $this->projects->create($request->user(), $request->validated());

        return $this->present($project)->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return $this->present($project);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $this->projects->update($project, $request->user(), $request->validated());

        return $this->present($project->refresh());
    }

    /**
     * Archive rather than destroy: task references and history stay intact.
     */
    public function destroy(Request $request, Project $project): Response
    {
        Gate::authorize('archive', $project);

        $this->projects->update($project, $request->user(), ['status' => ProjectStatus::Archived]);

        return response()->noContent();
    }

    public function addMember(StoreProjectMemberRequest $request, Project $project): ProjectResource
    {
        Gate::authorize('manageMembers', $project);

        $user = User::query()->findOrFail($request->integer('user_id'));
        $role = $request->filled('role')
            ? ProjectMemberRole::from($request->validated('role'))
            : ProjectMemberRole::Member;

        $this->projects->addMember($project, $user, $role, $request->user());

        return $this->present($project->refresh());
    }

    public function removeMember(Request $request, Project $project, User $user): ProjectResource
    {
        Gate::authorize('manageMembers', $project);

        abort_unless($project->members()->whereKey($user->id)->exists(), 404);

        $this->projects->removeMember($project, $user, $request->user());

        return $this->present($project->refresh());
    }

    private function present(Project $project): ProjectResource
    {
        $project->load(['ownerTeam', 'lead', 'members', 'activeSprint']);
        $project->loadCount([
            'members',
            'tasks',
            'tasks as open_tasks_count' => fn (Builder $query) => $query->whereIn('status', TaskStatus::openValues()),
        ]);

        return new ProjectResource($project);
    }
}
