<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Every ability first checks visibility (Incident::scopeVisibleTo). An incident the user
 * cannot see is reported as 404, so its existence is not revealed; a visible incident
 * without the needed permission gives 403.
 */
class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            PermissionKey::IncidentViewOwn->value,
            PermissionKey::IncidentViewTeam->value,
            PermissionKey::IncidentViewAll->value,
        ]);
    }

    public function view(User $user, Incident $incident): Response
    {
        return $incident->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionKey::IncidentCreate->value);
    }

    /**
     * Edit details and post updates. Reporters only see their own incidents, so for them
     * this is "own only".
     */
    public function update(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::IncidentUpdate);
    }

    public function acknowledge(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::IncidentAcknowledge);
    }

    /**
     * Status changes up to resolved, reopen and cancel. Responders (no incident.view.all)
     * may only move incidents assigned to them.
     */
    public function transition(User $user, Incident $incident): Response
    {
        return $this->visibleAnd(
            $user,
            $incident,
            PermissionKey::IncidentTransition,
            $incident->assignee_id === $user->id || $user->can(PermissionKey::IncidentViewAll->value),
        );
    }

    public function close(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::IncidentClose);
    }

    public function changeSeverity(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::IncidentSeverity);
    }

    public function assign(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::IncidentAssign);
    }

    public function delete(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::IncidentDelete);
    }

    public function logTime(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::TimeLog);
    }

    public function writeResolution(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::ResolutionWrite);
    }

    /**
     * Reporters upload only to their own incidents, since those are the only ones they see.
     */
    public function uploadDocument(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::DocumentUpload);
    }

    public function publishKnowledge(User $user, Incident $incident): Response
    {
        return $this->visibleAnd($user, $incident, PermissionKey::KnowledgePublish);
    }

    private function visibleAnd(User $user, Incident $incident, PermissionKey $permission, bool $condition = true): Response
    {
        if (! $incident->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $user->can($permission->value) && $condition ? Response::allow() : Response::deny();
    }
}
