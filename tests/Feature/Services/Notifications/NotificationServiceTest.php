<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEvent;
use App\Jobs\SendPushNotification;
use App\Models\Incident;
use App\Models\NotificationLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\InAppAlert;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\PushService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePushSender;

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    $this->push = new FakePushSender;
    // Built directly: replacing a container binding mid-test does not reliably reach an
    // already-constructed service.
    $this->service = new NotificationService(app(NotificationPreferences::class), new PushService($this->push));
    $this->user = User::factory()->create();
    $this->incident = Incident::factory()->create();
    $this->content = ['title' => 'Assigned to you: INC-1', 'body' => 'Deposits failing', 'url' => 'http://localhost:3000/incidents/1'];
});

/**
 * @param  array<string, mixed>  $settings
 */
function setPreferences(User $user, array $settings): void
{
    app(NotificationPreferences::class)->store($user, $settings);
}

it('writes the bell entry, queues the email and the push, and logs all three channels', function () {
    PushSubscription::factory()->for($this->user)->create();

    $this->service->send([$this->user], NotificationEvent::IncidentAssigned, $this->incident, $this->content, new IncidentNotice('Assigned', ['Line'], 'http://localhost:3000/incidents/1'));

    Notification::assertSentTo($this->user, InAppAlert::class);
    Notification::assertSentTo($this->user, IncidentNotice::class);
    Queue::assertPushed(SendPushNotification::class);

    expect(NotificationLog::where('user_id', $this->user->id)->pluck('status', 'channel')->all())->toBe([
        'in_app' => NotificationDeliveryStatus::Sent,
        'email' => NotificationDeliveryStatus::Queued,
        'push' => NotificationDeliveryStatus::Queued,
    ]);
});

it('stores the bell entry with its link to the incident', function () {
    Notification::fake();
    $this->app->instance(NotificationService::class, app(NotificationService::class));

    app(NotificationService::class)->sendTo($this->user, NotificationEvent::SlaBreached, $this->incident, $this->content);

    Notification::assertSentTo($this->user, InAppAlert::class, fn (InAppAlert $alert) => $alert->content['url'] === $this->content['url']
        && $alert->event === NotificationEvent::SlaBreached);
});

it('skips channels the person turned off', function () {
    PushSubscription::factory()->for($this->user)->create();
    setPreferences($this->user, ['events' => ['incident.assigned' => ['in_app']]]);

    $this->service->send([$this->user], NotificationEvent::IncidentAssigned, $this->incident, $this->content, new IncidentNotice('x', [], 'http://localhost'));

    Notification::assertSentTo($this->user, InAppAlert::class);
    Notification::assertNotSentTo($this->user, IncidentNotice::class);
    Queue::assertNotPushed(SendPushNotification::class);
    expect(NotificationLog::where('channel', 'push')->exists())->toBeFalse();
});

it('mutes push during quiet hours but keeps the bell and email, and lets critical events through', function () {
    $this->travelTo(now()->setTime(23, 30));
    PushSubscription::factory()->for($this->user)->create();
    setPreferences($this->user, ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']]);

    $this->service->send([$this->user], NotificationEvent::IncidentAssigned, $this->incident, $this->content, new IncidentNotice('x', [], 'http://localhost'));

    Notification::assertSentTo($this->user, InAppAlert::class);
    Notification::assertSentTo($this->user, IncidentNotice::class);
    Queue::assertNotPushed(SendPushNotification::class);

    // An SLA breach is critical: it ignores quiet hours.
    $this->service->send([$this->user], NotificationEvent::SlaBreached, $this->incident, $this->content);
    Queue::assertPushed(SendPushNotification::class);
});

it('uses push from a quiet hours range that crosses midnight', function () {
    $this->travelTo(now()->setTime(2, 0));
    $this->user->forceFill(['timezone' => 'UTC'])->save();
    setPreferences($this->user, ['quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']]);

    expect(app(NotificationPreferences::class)->inQuietHours($this->user))->toBeTrue();
});

it('never notifies the person who did the action, inactive people, or the same person twice', function () {
    $actor = $this->user;
    $inactive = User::factory()->create(['is_active' => false]);

    $this->service->send([$this->user, $this->user, $inactive], NotificationEvent::IncidentAssigned, $this->incident, $this->content, actor: $actor);

    Notification::assertNothingSent();
    expect(NotificationLog::count())->toBe(0);
});

it('logs why nothing was sent when every channel is off', function () {
    setPreferences($this->user, ['channels' => ['in_app' => false, 'push' => false, 'email' => false]]);

    $this->service->sendTo($this->user, NotificationEvent::IncidentAssigned, $this->incident, $this->content);

    Notification::assertNothingSent();
    expect(NotificationLog::sole()->status)->toBe(NotificationDeliveryStatus::Skipped);
});

it('does not queue push when the server has no VAPID keys', function () {
    $this->push->configured = false;
    PushSubscription::factory()->for($this->user)->create();

    $this->service->sendTo($this->user, NotificationEvent::IncidentAssigned, $this->incident, $this->content);

    Queue::assertNotPushed(SendPushNotification::class);
    expect(NotificationLog::where('channel', 'push')->exists())->toBeFalse()
        ->and(NotificationLog::where('channel', 'in_app')->exists())->toBeTrue();
});

it('does not queue push for someone with no subscribed browser', function () {
    $this->service->sendTo($this->user, NotificationEvent::IncidentAssigned, $this->incident, $this->content);

    Queue::assertNotPushed(SendPushNotification::class);
    expect(NotificationLog::where('channel', 'push')->exists())->toBeFalse();
});

it('starts with every channel on for every event and stores changes', function () {
    $preferences = app(NotificationPreferences::class);
    $defaults = $preferences->for($this->user);

    expect($defaults['events']['incident.assigned'])->toBe(['in_app', 'push', 'email'])
        ->and($defaults['events']['incident.status_changed'])->toBe(['in_app', 'push'])
        ->and($defaults['quiet_hours'])->toBe(['enabled' => false, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC']);

    $saved = $preferences->store($this->user, [
        'channels' => ['email' => false],
        'events' => ['incident.status_changed' => ['push']],
        'quiet_hours' => ['enabled' => true, 'start' => '21:00', 'end' => '06:00'],
        'unknown_event' => ['in_app'],
    ]);

    expect($saved['channels']['email'])->toBeFalse()
        // A global channel switch wins over an event-level choice.
        ->and($saved['events']['incident.status_changed'])->toBe(['push'])
        ->and($saved['events']['incident.assigned'])->toBe(['in_app', 'push'])
        ->and($saved)->not->toHaveKey('unknown_event');
});

it('marks the event and channel on the delivery log', function () {
    $this->service->sendTo($this->user, NotificationEvent::SlaWarning, $this->incident, $this->content);

    $log = NotificationLog::where('channel', NotificationChannel::InApp->value)->sole();

    expect($log->event)->toBe(NotificationEvent::SlaWarning)
        ->and($log->subject_id)->toBe($this->incident->id)
        ->and($log->subject_type)->toBe('incident')
        ->and($log->title)->toBe($this->content['title']);
});
