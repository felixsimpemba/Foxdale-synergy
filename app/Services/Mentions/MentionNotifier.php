<?php

namespace App\Services\Mentions;

use App\Enums\NotificationEvent;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Tells people they were mentioned in a comment, linking straight to it.
 */
class MentionNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  Collection<int, User>  $people
     */
    public function notify(Incident $incident, IncidentUpdate $update, User $author, Collection $people, string $body): void
    {
        if ($people->isEmpty()) {
            return;
        }

        $title = "You were mentioned on {$incident->ref}";
        $excerpt = Str::limit(trim($body), 160);
        $url = config('incidents.frontend_url')."/incidents/{$incident->id}?tab=activity#update-{$update->id}";

        $this->notifications->send(
            $people,
            NotificationEvent::IncidentMention,
            $incident,
            ['title' => $title, 'body' => "{$author->name}: {$excerpt}", 'url' => $url],
            new IncidentNotice($title, [
                "{$author->name} mentioned you on {$incident->ref} \"{$incident->title}\".",
                Str::limit(trim($body), 400),
            ], $url),
            $author,
        );
    }
}
