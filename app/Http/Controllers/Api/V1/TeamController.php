<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreTeamMemberRequest;
use App\Http\Requests\Catalog\StoreTeamRequest;
use App\Http\Requests\Catalog\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class TeamController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * ?include=members adds each team's active members, for people pickers (owners of RCA
     * action items, assignees) without one request per team.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Team::class);

        $withMembers = in_array('members', explode(',', (string) $request->query('include')), true);

        return TeamResource::collection(
            Team::query()
                ->with('lead')
                ->withCount('members')
                ->when($withMembers, fn ($query) => $query->with(['members' => fn ($members) => $members->where('is_active', true)->orderBy('name')]))
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        Gate::authorize('create', Team::class);

        $team = Team::create($request->validated());

        return (new TeamResource($team))->response()->setStatusCode(201);
    }

    public function show(Team $team): TeamResource
    {
        Gate::authorize('view', $team);

        return new TeamResource($team->load(['lead', 'members' => fn ($query) => $query->orderBy('name')]));
    }

    public function update(UpdateTeamRequest $request, Team $team): TeamResource
    {
        Gate::authorize('update', $team);

        $team->update($request->validated());

        return new TeamResource($team->load('lead'));
    }

    public function destroy(Team $team): Response
    {
        Gate::authorize('delete', $team);

        $team->delete();

        return response()->noContent();
    }

    /**
     * Add a member or change whether they lead the team.
     */
    public function addMember(StoreTeamMemberRequest $request, Team $team): TeamResource
    {
        Gate::authorize('manageMembers', $team);

        $user = User::findOrFail($request->validated('user_id'));
        $isLead = $request->boolean('is_lead');

        $team->addMember($user, $isLead);
        $this->audit->record('team.member_added', $team, ['user_id' => $user->id, 'is_lead' => $isLead]);

        return new TeamResource($team->refresh()->load(['lead', 'members']));
    }

    public function removeMember(Team $team, User $user): TeamResource
    {
        Gate::authorize('manageMembers', $team);

        abort_unless($team->members()->whereKey($user->id)->exists(), 404);

        $team->removeMember($user);
        $this->audit->record('team.member_removed', $team, ['user_id' => $user->id]);

        return new TeamResource($team->refresh()->load(['lead', 'members']));
    }
}
