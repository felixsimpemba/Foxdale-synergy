<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Users edit and remove only their own time entries.
 */
class TimeEntryPolicy
{
    public function update(User $user, TimeEntry $timeEntry): Response
    {
        return $this->ownEntry($user, $timeEntry);
    }

    public function delete(User $user, TimeEntry $timeEntry): Response
    {
        return $this->ownEntry($user, $timeEntry);
    }

    private function ownEntry(User $user, TimeEntry $timeEntry): Response
    {
        if ($timeEntry->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $user->can(PermissionKey::TimeLog->value) ? Response::allow() : Response::deny();
    }
}
