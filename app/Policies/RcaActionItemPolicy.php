<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * RCA writers manage every item; an owner may always update and complete their own items,
 * even without rca.write. Owners see their items even when the incident is not visible to
 * them, since the item was given to them to do.
 */
class RcaActionItemPolicy
{
    public function update(User $user, RcaActionItem $item): Response
    {
        if ($item->owner_id === $user->id) {
            return Response::allow();
        }

        $rca = Rca::query()->find($item->rca_id);
        $incident = $rca === null ? null : Incident::query()->find($rca->incident_id);

        if ($incident === null || ! $incident->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $user->can(PermissionKey::RcaWrite->value)
            ? Response::allow()
            : Response::deny('Only the owner or an RCA writer can change this action item.');
    }
}
