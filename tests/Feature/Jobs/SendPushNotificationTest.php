<?php

use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEvent;
use App\Jobs\SendPushNotification;
use App\Models\NotificationLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\PushResult;
use App\Services\Notifications\PushService;
use Tests\Support\FakePushSender;

beforeEach(function () {
    $this->push = new FakePushSender;
    $this->user = User::factory()->create();
    $this->content = ['title' => 'SLA breached', 'body' => 'INC-1 missed its ack target', 'url' => 'http://localhost:3000/incidents/1'];
    $this->log = NotificationLog::create([
        'user_id' => $this->user->id,
        'event' => NotificationEvent::SlaBreached,
        'channel' => 'push',
        'status' => NotificationDeliveryStatus::Queued,
        'title' => $this->content['title'],
    ]);
});

function runPushJob(User $user, array $content, ?int $logId): void
{
    (new SendPushNotification($user->id, NotificationEvent::SlaBreached->value, $content, $logId))
        ->handle(new PushService(test()->push));
}

it('pushes to every subscribed browser and marks the log sent', function () {
    $first = PushSubscription::factory()->for($this->user)->create();
    $second = PushSubscription::factory()->for($this->user)->create();

    runPushJob($this->user, $this->content, $this->log->id);

    expect($this->push->sent)->toHaveCount(2)
        ->and($this->push->sent[0]['payload']['title'])->toBe('SLA breached')
        ->and($this->push->sent[0]['payload']['event'])->toBe('sla.breached')
        ->and($first->fresh()->last_used_at)->not->toBeNull()
        ->and($second->fresh()->last_used_at)->not->toBeNull()
        ->and($this->log->fresh()->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($this->log->fresh()->error)->toBeNull();
});

it('forgets browsers that say the subscription is gone', function () {
    PushSubscription::factory()->for($this->user)->count(2)->create();
    $this->push->result = PushResult::expired('410 Gone');

    runPushJob($this->user, $this->content, $this->log->id);

    expect(PushSubscription::count())->toBe(0)
        ->and($this->log->fresh()->status)->toBe(NotificationDeliveryStatus::Failed);
});

it('records why a push failed but still tries the other browsers', function () {
    PushSubscription::factory()->for($this->user)->create();
    $this->push->result = PushResult::failed('413 payload too large');

    runPushJob($this->user, $this->content, $this->log->id);

    expect(PushSubscription::count())->toBe(1)
        ->and($this->log->fresh()->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($this->log->fresh()->error)->toBe('413 payload too large');
});

it('does nothing for a deactivated user', function () {
    PushSubscription::factory()->for($this->user)->create();
    $this->user->forceFill(['is_active' => false])->save();

    runPushJob($this->user, $this->content, $this->log->id);

    expect($this->push->sent)->toBeEmpty();
});
