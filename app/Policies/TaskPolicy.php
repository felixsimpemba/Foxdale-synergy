<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Task abilities are checked against the task's project. Viewers can read; members and
 * managers can contribute; managers (and project.manage holders) can run the project.
 */
class TaskPolicy
{
    public function viewAny(User $user, Project $project): Response
    {
        return $project->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, Task $task): Response
    {
        return $this->visible($user, $task);
    }

    public function create(User $user, Project $project): Response
    {
        if (! $project->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $user->can(PermissionKey::TaskCreate->value) && $project->canContributeBy($user)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Task $task): Response
    {
        return $this->visibleAnd(
            $user,
            $task,
            PermissionKey::TaskUpdate,
            $this->projectOf($task)->canContributeBy($user),
        );
    }

    public function assign(User $user, Task $task): Response
    {
        return $this->visibleAnd(
            $user,
            $task,
            PermissionKey::TaskAssign,
            $this->projectOf($task)->canContributeBy($user),
        );
    }

    /**
     * The assigned reviewer decides, or a project manager. The author is kept out in
     * TaskService::approve(), matching the RCA rule.
     */
    public function review(User $user, Task $task): Response
    {
        $project = $this->projectOf($task);

        if (! $project->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        // The assigned reviewer is authorised by the assignment itself. The global
        // task.review permission is for managers who decide tasks they were not assigned.
        $isAssignedReviewer = $task->reviewer_id === $user->id;
        $isManager = $user->can(PermissionKey::TaskReview->value)
            && $project->canBeManagedBy($user);

        return $isAssignedReviewer || $isManager ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Task $task): Response
    {
        return $this->visibleAnd(
            $user,
            $task,
            PermissionKey::TaskDelete,
            $this->projectOf($task)->canContributeBy($user),
        );
    }

    private function visible(User $user, Task $task): Response
    {
        return $this->projectOf($task)->isVisibleTo($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function visibleAnd(User $user, Task $task, PermissionKey $permission, bool $condition): Response
    {
        if (! $this->projectOf($task)->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $user->can($permission->value) && $condition ? Response::allow() : Response::deny();
    }

    private function projectOf(Task $task): Project
    {
        return Project::query()->findOrFail($task->project_id);
    }
}
