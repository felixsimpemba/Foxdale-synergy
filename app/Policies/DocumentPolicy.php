<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Documents follow their incident's visibility (404 when it is hidden). Uploaders delete
 * their own files; incident managers and admins (document.delete) delete any.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): Response
    {
        return $document->incident->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function addVersion(User $user, Document $document): Response
    {
        if (! $document->incident->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        return $user->can(PermissionKey::DocumentUpload->value) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Document $document): Response
    {
        if (! $document->incident->isVisibleTo($user)) {
            return Response::denyAsNotFound();
        }

        $ownUpload = $document->uploaded_by === $user->id && $user->can(PermissionKey::DocumentUpload->value);

        return $ownUpload || $user->can(PermissionKey::DocumentDelete->value) ? Response::allow() : Response::deny();
    }
}
