<?php

use App\Enums\IncidentStatus;
use App\Enums\RcaStatus;
use App\Enums\RoleName;
use App\Enums\Severity;
use App\Jobs\SendActionItemDigest;
use App\Jobs\SendRcaReminders;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\User;
use App\Notifications\ActionItemsOverdue;
use App\Notifications\RcaDue;
use App\Services\Notifications\NotificationService;
use App\Services\RcaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

function resolvedHighIncident(array $attributes = []): Incident
{
    return Incident::factory()->severity(Severity::Sev1)->inStatus(IncidentStatus::Resolved)->create($attributes);
}

describe('RCA due reminders', function () {
    it('reminds the author and incident managers of an overdue RCA, once a day', function () {
        $manager = userWithRole(RoleName::IncidentManager);
        $author = User::factory()->create();
        $rca = Rca::factory()->create([
            'incident_id' => resolvedHighIncident()->id,
            'author_id' => $author->id,
            'due_at' => now()->subDay(),
        ]);

        (new SendRcaReminders)->handle(app(RcaService::class), app(NotificationService::class));

        Notification::assertSentTo([$author, $manager], RcaDue::class, fn (RcaDue $notification) => $notification->state === 'still a draft');
        expect($rca->fresh()->last_reminded_at)->not->toBeNull();

        // A second run the same day sends nothing new.
        (new SendRcaReminders)->handle(app(RcaService::class), app(NotificationService::class));
        Notification::assertSentToTimes($author, RcaDue::class, 1);
    });

    it('reminds the incident owner when no RCA was started', function () {
        $owner = User::factory()->create();
        resolvedHighIncident(['assignee_id' => $owner->id, 'resolved_at' => now()->subWeeks(2)]);

        (new SendRcaReminders)->handle(app(RcaService::class), app(NotificationService::class));

        Notification::assertSentTo($owner, RcaDue::class, fn (RcaDue $notification) => $notification->state === 'not started');
    });

    it('leaves alone RCAs not due yet, approved ones, and SEV3 incidents', function () {
        $author = User::factory()->create();
        Rca::factory()->create(['incident_id' => resolvedHighIncident()->id, 'author_id' => $author->id, 'due_at' => now()->addDay()]);
        Rca::factory()->inStatus(RcaStatus::Approved)->create(['incident_id' => resolvedHighIncident()->id, 'author_id' => $author->id, 'due_at' => now()->subDay()]);
        Incident::factory()->severity(Severity::Sev3)->inStatus(IncidentStatus::Resolved)->create([
            'assignee_id' => $author->id,
            'resolved_at' => now()->subWeeks(2),
        ]);

        (new SendRcaReminders)->handle(app(RcaService::class), app(NotificationService::class));

        Notification::assertNothingSent();
    });
});

describe('action item reminders', function () {
    it('sends each owner one email listing their overdue items', function () {
        $owner = User::factory()->create();
        RcaActionItem::factory()->overdue()->count(2)->create(['owner_id' => $owner->id]);
        RcaActionItem::factory()->create(['owner_id' => $owner->id]);
        RcaActionItem::factory()->overdue()->create(['owner_id' => $owner->id, 'status' => 'done']);

        (new SendRcaReminders)->handle(app(RcaService::class), app(NotificationService::class));

        Notification::assertSentToTimes($owner, ActionItemsOverdue::class, 1);
        Notification::assertSentTo($owner, ActionItemsOverdue::class, fn (ActionItemsOverdue $notification) => $notification->items->count() === 2 && ! $notification->digest);
    });

    it('does not email deactivated owners', function () {
        RcaActionItem::factory()->overdue()->create(['owner_id' => User::factory()->create(['is_active' => false])->id]);

        (new SendRcaReminders)->handle(app(RcaService::class), app(NotificationService::class));

        Notification::assertNothingSent();
    });

    it('sends incident managers a weekly digest of every overdue item', function () {
        $manager = userWithRole(RoleName::IncidentManager);
        RcaActionItem::factory()->overdue()->count(3)->create();

        (new SendActionItemDigest)->handle(app(RcaService::class), app(NotificationService::class));

        Notification::assertSentTo($manager, ActionItemsOverdue::class, fn (ActionItemsOverdue $notification) => $notification->digest && $notification->items->count() === 3);
    });

    it('renders the reminder email with each item', function () {
        $owner = User::factory()->create(['name' => 'Ruth Phiri']);
        $item = RcaActionItem::factory()->overdue()->create(['owner_id' => $owner->id, 'title' => 'Alert on queue depth']);
        $item->load('owner', 'rca.incident');

        $mail = (new ActionItemsOverdue(new Collection([$item])))->toMail($owner);

        expect($mail->subject)->toBe('You have 1 overdue action item')
            ->and(implode("\n", $mail->introLines))->toContain('Alert on queue depth')
            ->and($mail->actionUrl)->toEndWith('/action-items?scope=mine');
    });
});
