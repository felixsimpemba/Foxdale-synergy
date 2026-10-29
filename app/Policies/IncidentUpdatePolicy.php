<?php

namespace App\Policies;

use App\Models\IncidentUpdate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authors edit and delete their own comments and status updates. System notes and AI drafts
 * cannot be changed. Updates follow their incident's visibility (404 when it is hidden).
 */
class IncidentUpdatePolicy
{
    public function update(User $user, IncidentUpdate $update): Response
    {
        return $this->ownConversation($user, $update);
    }

    public function delete(User $user, IncidentUpdate $update): Response
    {
        return $this->ownConversation($user, $update);
    }

    private function ownConversation(User $user, IncidentUpdate $update): Response
    {
        $incident = $update->incident()->withTrashed()->first();

        if ($incident === null || ! $incident->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $update->isConversation() && $update->user_id === $user->id
            ? Response::allow()
            : Response::deny('Only the author can change this comment.');
    }
}
