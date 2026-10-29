<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\NotificationEvent;
use App\Enums\RcaStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\RcaActionItem;
use App\Models\User;
use App\Notifications\ActionItemsOverdue;
use App\Notifications\RcaDue;
use App\Notifications\RcaLinks;
use App\Services\Notifications\NotificationService;
use App\Services\RcaService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs daily (specification section 8):
 * - SEV1 and SEV2 incidents whose RCA is past due and not approved: remind the RCA author
 *   (or the incident owner when no RCA was started) and incident managers.
 * - Overdue action items: one email per owner listing theirs.
 *
 * Anything reminded in the last 20 hours is skipped, so a re-run on the same day sends nothing.
 */
class SendRcaReminders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function handle(RcaService $rcas, NotificationService $notifications): void
    {
        $this->remindDueRcas($rcas, $notifications);
        $this->remindActionItemOwners($notifications);
    }

    private function remindDueRcas(RcaService $rcas, NotificationService $notifications): void
    {
        $now = now();
        $managers = $rcas->reviewers();

        Incident::query()
            ->where('status', IncidentStatus::Resolved->value)
            ->whereIn('severity', [Severity::Sev1->value, Severity::Sev2->value])
            ->whereNotNull('resolved_at')
            ->whereDoesntHave('rca', fn (Builder $rca) => $rca->where('status', RcaStatus::Approved->value))
            ->with(['rca', 'assignee'])
            ->chunkById(100, function (Collection $incidents) use ($rcas, $managers, $notifications, $now): void {
                foreach ($incidents as $incident) {
                    $rca = $incident->rca;
                    $dueAt = $rca->due_at ?? $rcas->dueAt($incident->resolved_at);

                    if ($dueAt->isFuture() || ($rca?->last_reminded_at !== null && $rca->last_reminded_at->gt($now->copy()->subHours(20)))) {
                        continue;
                    }

                    $owner = $rca === null
                        ? $incident->assignee
                        : User::query()->find($rca->author_id);

                    $recipients = $managers
                        ->concat($owner !== null && $owner->is_active ? [$owner] : [])
                        ->unique('id');

                    $state = match ($rca?->status) {
                        null => 'not started',
                        RcaStatus::Draft => 'still a draft',
                        RcaStatus::InReview => 'waiting for review',
                        RcaStatus::Rejected => 'returned for changes',
                        RcaStatus::Approved => 'approved',
                    };

                    $notifications->send(
                        $recipients,
                        NotificationEvent::RcaDue,
                        $rca ?? $incident,
                        [
                            'title' => "RCA overdue: {$incident->ref}",
                            'body' => "The root cause analysis of \"{$incident->title}\" is {$state}.",
                            'url' => RcaLinks::incident($incident),
                        ],
                        new RcaDue($incident, $dueAt, $state),
                    );
                    $rca?->forceFill(['last_reminded_at' => $now])->saveQuietly();
                }
            });
    }

    private function remindActionItemOwners(NotificationService $notifications): void
    {
        $now = now();

        RcaActionItem::query()
            ->overdue()
            ->whereNotNull('owner_id')
            ->where(fn (Builder $query) => $query
                ->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<', $now->copy()->subHours(20)))
            ->with(['owner', 'rca.incident'])
            ->get()
            ->groupBy('owner_id')
            ->each(function (Collection $items) use ($notifications, $now): void {
                $owner = $items->first()?->owner;

                if ($owner === null || ! $owner->is_active) {
                    return;
                }

                $notifications->send(
                    [$owner],
                    NotificationEvent::ActionItemOverdue,
                    null,
                    [
                        'title' => $items->count() === 1 ? 'You have 1 overdue action item' : "You have {$items->count()} overdue action items",
                        'body' => $items->take(3)->map(fn (RcaActionItem $item): string => $item->title)->implode(' · '),
                        'url' => RcaLinks::actionItems(),
                    ],
                    new ActionItemsOverdue($items),
                );

                RcaActionItem::query()->whereKey($items->modelKeys())->update(['last_reminded_at' => $now]);
            });
    }
}
