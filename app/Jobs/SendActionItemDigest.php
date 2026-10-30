<?php

namespace App\Jobs;

use App\Enums\NotificationEvent;
use App\Models\RcaActionItem;
use App\Notifications\ActionItemsOverdue;
use App\Notifications\RcaLinks;
use App\Services\Notifications\NotificationService;
use App\Services\RcaService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs weekly: incident managers get one email listing every overdue action item.
 */
class SendActionItemDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function handle(RcaService $rcas, NotificationService $notifications): void
    {
        $items = RcaActionItem::query()
            ->overdue()
            ->with(['owner', 'rca.incident'])
            ->orderBy('due_date')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $notifications->send(
            $rcas->reviewers(),
            NotificationEvent::ActionItemOverdue,
            null,
            [
                'title' => "Weekly digest: {$items->count()} overdue action items",
                'body' => $items->take(3)->map(fn (RcaActionItem $item): string => $item->title)->implode(' · '),
                'url' => RcaLinks::actionItems(true),
            ],
            new ActionItemsOverdue($items, digest: true),
        );
    }
}
