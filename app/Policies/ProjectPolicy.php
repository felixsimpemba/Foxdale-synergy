<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Project visibility is scoped: members, the lead, the owner team and holders of
 * project.manage. A project the user cannot see is reported as 404; a visible project
 * they cannot change gives 403.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionKey::ProjectView->value)
            || $user->can(PermissionKey::ProjectManage->value);
    }

    public function view(User $user, Project $project): Response
    {
        return $project->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::ProjectManage->value);
    }

    public function update(User $user, Project $project): Response
    {
        return $this->manage($user, $project);
    }

    public function manageMembers(User $user, Project $project): Response
    {
        return $this->manage($user, $project);
    }

    public function manageSprints(User $user, Project $project): Response
    {
        return $this->manage($user, $project);
    }

    public function archive(User $user, Project $project): Response
    {
        return $this->manage($user, $project);
    }

    private function manage(User $user, Project $project): Response
    {
        if (! $project->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $project->canBeManagedBy($user) ? Response::allow() : Response::deny();
    }
}
