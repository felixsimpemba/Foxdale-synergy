<?php

namespace App\Http\Controllers\Api\V1\Incidents;

use App\Actions\Incidents\PostIncidentUpdate;
use App\Enums\IncidentUpdateType;
use App\Enums\UpdateVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\StoreIncidentUpdateRequest;
use App\Http\Requests\Incidents\UpdateIncidentUpdateRequest;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Mentions\Mentioner;
use App\Services\Mentions\MentionNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class IncidentUpdateController extends Controller
{
    /**
     * Every update, newest first, flat: replies carry parent_id. The timeline endpoint
     * returns the same updates grouped into threads.
     */
    public function index(Request $request, Incident $incident): AnonymousResourceCollection
    {
        Gate::authorize('view', $incident);

        return IncidentUpdateResource::collection(
            $incident->updates()
                ->with('user', 'attachments', 'mentions')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request, 50)),
        );
    }

    /**
     * Post a comment or status update, a reply (parent_id), and/or files (attachments[],
     * multipart). notify_channels=true also pushes it to chat.
     */
    public function store(StoreIncidentUpdateRequest $request, Incident $incident, PostIncidentUpdate $postUpdate): JsonResponse
    {
        Gate::authorize('update', $incident);

        $attachments = array_values($request->file('attachments', []));

        if ($attachments !== []) {
            Gate::authorize('uploadDocument', $incident);
        }

        $update = $postUpdate->handle(
            $incident,
            $request->user(),
            (string) $request->validated('body'),
            IncidentUpdateType::from($request->validated('type') ?? IncidentUpdateType::Comment->value),
            UpdateVisibility::from($request->validated('visibility') ?? UpdateVisibility::Internal->value),
            $request->boolean('notify_channels'),
            $request->filled('parent_id') ? IncidentUpdate::query()->findOrFail($request->integer('parent_id')) : null,
            $attachments,
        );

        return (new IncidentUpdateResource($update->load('user', 'attachments', 'mentions')))->response()->setStatusCode(201);
    }

    /**
     * Edit the text of your own comment; it is marked as edited and the change is audited.
     */
    public function update(
        UpdateIncidentUpdateRequest $request,
        IncidentUpdate $incidentUpdate,
        AuditLogger $audit,
        Mentioner $mentioner,
        MentionNotifier $mentionNotifier,
    ): IncidentUpdateResource {
        Gate::authorize('update', $incidentUpdate);

        $before = $incidentUpdate->body;
        $body = (string) $request->validated('body');
        $incidentUpdate->forceFill(['body' => $body, 'edited_at' => now()])->save();

        $audit->record('incident_update.edited', $incidentUpdate->incident()->withTrashed()->firstOrFail(), [
            'update_id' => $incidentUpdate->id,
            'before' => ['body' => $before],
            'after' => ['body' => $incidentUpdate->body],
        ]);

        // Editing can add or remove mentions; only newly mentioned people are told.
        $incident = Incident::query()->findOrFail($incidentUpdate->incident_id);
        $author = $request->user();
        $previous = $incidentUpdate->mentions()->pluck('users.id');
        $mentioned = $mentioner->mentionedIn($body, $mentioner->mentionable($incident, $author));
        $incidentUpdate->mentions()->sync($mentioned->modelKeys());
        $added = $mentioned->reject(fn (User $person): bool => $previous->contains($person->id));

        if ($added->isNotEmpty()) {
            $mentionNotifier->notify($incident, $incidentUpdate, $author, $added, $body);
        }

        return new IncidentUpdateResource($incidentUpdate->load('user', 'attachments', 'mentions'));
    }

    /**
     * People who can be @mentioned on this incident: everyone who can see it.
     */
    public function mentionable(Request $request, Incident $incident, Mentioner $mentioner): JsonResponse
    {
        Gate::authorize('view', $incident);

        return response()->json(['data' => $mentioner->mentionable($incident, $request->user())
            ->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])
            ->values()
            ->all()]);
    }

    /**
     * Delete your own comment and the files attached to it. A comment with replies stays as
     * a "deleted" placeholder so the rest of the thread still makes sense.
     */
    public function destroy(IncidentUpdate $incidentUpdate, AuditLogger $audit): Response
    {
        Gate::authorize('delete', $incidentUpdate);

        DB::transaction(function () use ($incidentUpdate, $audit): void {
            foreach ($incidentUpdate->attachments()->get() as $document) {
                $document->delete();
            }

            $incidentUpdate->delete();

            $audit->record('incident_update.deleted', $incidentUpdate->incident()->withTrashed()->firstOrFail(), [
                'update_id' => $incidentUpdate->id,
                'before' => ['body' => $incidentUpdate->body],
            ]);
        });

        return response()->noContent();
    }
}
