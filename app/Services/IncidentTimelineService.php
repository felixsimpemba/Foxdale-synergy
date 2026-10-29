<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Http\Resources\AttachmentResource;
use App\Models\Incident;
use App\Models\IncidentAssignment;
use App\Models\IncidentUpdate;
use App\Models\StatusHistory;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The merged activity feed of an incident and the time it spent in each status.
 */
class IncidentTimelineService
{
    /**
     * Status changes, assignments, updates and time entries, oldest first. Items in the same
     * second keep that order (timestamps are stored to the second).
     *
     * Comments appear once per thread, at the time the thread started, with their replies
     * (oldest first) and attached files inside. A deleted comment only appears while it still
     * has replies, as a placeholder without text.
     *
     * @return Collection<int, array{type: string, at: string, actor: array{id: int, name: string}|null, data: array<string, mixed>}>
     */
    public function timeline(Incident $incident): Collection
    {
        $incident->load([
            'statusHistory.user', 'assignments.assigner', 'assignments.toUser',
            'assignments.fromUser', 'assignments.team', 'timeEntries.user',
        ]);

        $threads = $incident->updates()
            ->withTrashed()
            ->whereNull('parent_id')
            ->with(['user', 'attachments', 'replies' => fn ($query) => $query->with('user', 'attachments')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (IncidentUpdate $update): bool => $update->trashed() && $update->replies->isEmpty());

        $items = collect()
            ->concat($incident->statusHistory->map(fn (StatusHistory $change) => $this->item('status_change', $change->changed_at, $change->user, [
                'from' => $change->from_status?->value,
                'to' => $change->to_status->value,
                'note' => $change->note,
            ])))
            ->concat($incident->assignments->map(fn (IncidentAssignment $assignment) => $this->item('assignment', $assignment->created_at, $assignment->assigner, [
                'from_user' => $this->person($assignment->fromUser),
                'to_user' => $this->person($assignment->toUser),
                'team' => $assignment->team === null ? null : ['id' => $assignment->team->id, 'name' => $assignment->team->name],
                'reason' => $assignment->reason,
            ])))
            ->concat($threads->map(fn (IncidentUpdate $update) => $this->item('update', $update->created_at, $update->trashed() ? null : $update->user, [
                ...$this->updateData($update),
                'replies' => $update->replies->map(fn (IncidentUpdate $reply): array => [
                    ...$this->updateData($reply),
                    'at' => $reply->created_at?->toIso8601ZuluString(),
                    'actor' => $this->person($reply->user),
                ])->values()->all(),
                'last_activity_at' => ($update->replies->last()->created_at ?? $update->created_at)?->toIso8601ZuluString(),
            ])))
            ->concat($incident->timeEntries->map(fn (TimeEntry $entry) => $this->item('time_entry', $entry->created_at, $entry->user, [
                'id' => $entry->id,
                'minutes' => $entry->minutes,
                'note' => $entry->note,
                'source' => $entry->source->value,
                'is_running' => $entry->isRunning(),
            ])));

        return $items->sortBy('at')->values();
    }

    /**
     * Seconds spent in each status so far, including the current one up to now
     * (closed and cancelled incidents stop counting).
     *
     * @return array<string, int>
     */
    public function secondsPerStatus(Incident $incident): array
    {
        $history = $incident->statusHistory()->orderBy('changed_at')->orderBy('id')->get();
        $totals = [];

        foreach ($history as $change) {
            if ($change->from_status !== null && $change->seconds_in_previous !== null) {
                $totals[$change->from_status->value] = ($totals[$change->from_status->value] ?? 0) + $change->seconds_in_previous;
            }
        }

        $last = $history->last();

        if ($last !== null && ! in_array($incident->status, [IncidentStatus::Closed, IncidentStatus::Cancelled], true)) {
            $current = $incident->status->value;
            $totals[$current] = ($totals[$current] ?? 0) + (int) $last->changed_at->diffInSeconds(now());
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    private function updateData(IncidentUpdate $update): array
    {
        $deleted = $update->trashed();

        return [
            'id' => $update->id,
            'update_type' => $update->type->value,
            'body' => $deleted ? null : $update->body,
            'visibility' => $update->visibility->value,
            'is_deleted' => $deleted,
            'edited_at' => $update->edited_at?->toIso8601ZuluString(),
            'attachments' => $deleted ? [] : AttachmentResource::collection($update->attachments)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, at: string, actor: array{id: int, name: string}|null, data: array<string, mixed>}
     */
    private function item(string $type, ?CarbonInterface $at, ?User $actor, array $data): array
    {
        return [
            'type' => $type,
            'at' => $at?->toIso8601ZuluString() ?? '',
            'actor' => $this->person($actor),
            'data' => $data,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
