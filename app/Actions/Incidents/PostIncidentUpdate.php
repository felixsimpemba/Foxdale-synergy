<?php

namespace App\Actions\Incidents;

use App\Enums\DocumentType;
use App\Enums\IncidentUpdateType;
use App\Enums\UpdateVisibility;
use App\Events\UpdatePosted;
use App\Models\Document;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Post a comment or status update on the timeline, optionally as a reply and with files.
 * With $notifyChannels the update is also pushed to WhatsApp and Mattermost (delivered by
 * the notifications module).
 *
 * Replies always join the top-level comment of the thread, are plain comments, and keep the
 * thread's visibility, so an internal discussion cannot be continued in front of stakeholders.
 */
class PostIncidentUpdate
{
    public function __construct(private DocumentService $documents) {}

    /**
     * @param  list<UploadedFile>  $attachments  stored as incident documents linked to the update
     */
    public function handle(
        Incident $incident,
        ?User $author,
        string $body,
        IncidentUpdateType $type = IncidentUpdateType::Comment,
        UpdateVisibility $visibility = UpdateVisibility::Internal,
        bool $notifyChannels = false,
        ?IncidentUpdate $replyTo = null,
        array $attachments = [],
    ): IncidentUpdate {
        $thread = $replyTo?->parent_id !== null ? IncidentUpdate::withTrashed()->findOrFail($replyTo->parent_id) : $replyTo;

        if ($thread !== null) {
            $type = IncidentUpdateType::Comment;
            $visibility = $thread->visibility;
        }

        /** @var list<Document> $stored */
        $stored = [];

        try {
            $update = DB::transaction(function () use ($incident, $author, $body, $type, $visibility, $thread, $attachments, &$stored): IncidentUpdate {
                $update = $incident->updates()->create([
                    'parent_id' => $thread?->id,
                    'user_id' => $author?->id,
                    'type' => $type,
                    'body' => $body,
                    'visibility' => $visibility,
                ]);

                foreach ($attachments as $file) {
                    $stored[] = $this->documents->upload($incident, $author ?? throw new LogicException('Attachments need an author.'), $file, [
                        'type' => str_starts_with((string) $file->getMimeType(), 'image/') ? DocumentType::Evidence->value : DocumentType::Other->value,
                        'title' => Str::limit($file->getClientOriginalName(), 200, ''),
                        'incident_update_id' => $update->id,
                    ]);
                }

                return $update;
            });
        } catch (Throwable $e) {
            // Files stored before the failure would otherwise be left on disk with no record.
            foreach ($stored as $document) {
                Storage::disk($document->disk)->delete($document->path);
            }

            throw $e;
        }

        UpdatePosted::dispatch($update, $notifyChannels);

        return $update;
    }
}
