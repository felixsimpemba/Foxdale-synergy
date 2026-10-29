<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * An RCA follows its incident's visibility (404 when the incident is hidden). Writers hold
 * rca.write; reviewers hold rca.approve and may not review an RCA they wrote.
 */
class RcaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            PermissionKey::IncidentViewOwn->value,
            PermissionKey::IncidentViewTeam->value,
            PermissionKey::IncidentViewAll->value,
        ]);
    }

    public function view(User $user, Rca $rca): Response
    {
        return $this->visible($user, $rca) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user, Incident $incident): Response
    {
        if (! $incident->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $user->can(PermissionKey::RcaWrite->value) ? Response::allow() : Response::deny();
    }

    /**
     * Edit, submit for review and manage action items.
     */
    public function update(User $user, Rca $rca): Response
    {
        if (! $this->visible($user, $rca)) {
            return Response::denyAsNotFound();
        }

        return $user->can(PermissionKey::RcaWrite->value) ? Response::allow() : Response::deny();
    }

    /**
     * Approve or reject.
     */
    public function review(User $user, Rca $rca): Response
    {
        if (! $this->visible($user, $rca)) {
            return Response::denyAsNotFound();
        }

        if (! $user->can(PermissionKey::RcaApprove->value)) {
            return Response::deny();
        }

        return $rca->author_id === $user->id
            ? Response::deny('Someone other than the author must review this root cause analysis.')
            : Response::allow();
    }

    private function visible(User $user, Rca $rca): bool
    {
        $incident = Incident::query()->find($rca->incident_id);

        return $incident !== null && $incident->isVisibleTo($user);
    }
}
