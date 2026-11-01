<?php

use App\Enums\NotificationEvent;
use App\Enums\RoleName;
use App\Models\NotificationLog;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\InAppAlert;
use App\Services\Notifications\PushService;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePushSender;

function alert(User $user, NotificationEvent $event = NotificationEvent::IncidentAssigned): void
{
    $user->notify(new InAppAlert($event, [
        'title' => 'Assigned to you: INC-2026-00001',
        'body' => 'Deposits failing',
        'url' => 'http://localhost:3000/incidents/1',
    ]));
}

describe('the bell', function () {
    it('lists only my notifications, newest first, with the unread count', function () {
        $me = actingAsRole(RoleName::Responder);
        alert($me);
        alert($me);
        alert(User::factory()->create());

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'Deposits failing')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('meta.unread', 2);
    });

    it('filters to unread and marks one or all read', function () {
        $me = actingAsRole(RoleName::Responder);
        alert($me);
        alert($me);
        $first = $me->notifications()->oldest('created_at')->first();

        $this->postJson("/api/v1/notifications/{$first->id}/read")->assertOk()->assertJsonPath('data.read_at', fn ($at) => $at !== null);
        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread', 1);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.unread', 0);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 0);
    });

    it('never lets me read someone else\'s notification', function () {
        actingAsRole(RoleName::Responder);
        $other = User::factory()->create();
        alert($other);
        $theirs = $other->notifications()->first();

        $this->postJson("/api/v1/notifications/{$theirs->id}/read")->assertNotFound();
    });

    it('deletes one of my notifications and nobody else\'s', function () {
        $me = actingAsRole(RoleName::Responder);
        alert($me);
        $mine = $me->notifications()->first();

        $this->deleteJson("/api/v1/notifications/{$mine->id}")->assertNoContent();
        expect($me->notifications()->count())->toBe(0);

        $other = User::factory()->create();
        alert($other);
        $theirs = $other->notifications()->first();

        $this->deleteJson("/api/v1/notifications/{$theirs->id}")->assertNotFound();
        expect($other->notifications()->count())->toBe(1);
    });
});

describe('push subscriptions', function () {
    it('says push is off when the server has no VAPID keys', function () {
        actingAsRole(RoleName::Responder);
        config(['incidents.push.vapid_public_key' => null, 'incidents.push.vapid_private_key' => null]);

        $this->getJson('/api/v1/push/config')->assertOk()->assertJsonPath('data.enabled', false);
    });

    it('hands the browser the application server key when push is on', function () {
        actingAsRole(RoleName::Responder);
        config(['incidents.push.vapid_public_key' => 'BPublicKey', 'incidents.push.vapid_private_key' => 'PrivateKey']);

        $this->getJson('/api/v1/push/config')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.public_key', 'BPublicKey');
    });

    it('stores a browser once, even when it subscribes again', function () {
        $me = actingAsRole(RoleName::Responder);
        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-secret'],
        ];

        $this->postJson('/api/v1/push/subscriptions', $payload)->assertCreated()->assertJsonPath('data.device', 'Browser');
        $this->postJson('/api/v1/push/subscriptions', [...$payload, 'keys' => ['p256dh' => 'rotated', 'auth' => 'rotated']])->assertCreated();

        expect(PushSubscription::where('user_id', $me->id)->sole())
            ->public_key->toBe('rotated')
            ->and(PushSubscription::count())->toBe(1);
    });

    it('moves a shared browser to whoever is signed in now', function () {
        $first = PushSubscription::factory()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/shared']);
        $second = actingAsRole(RoleName::Responder);

        $this->postJson('/api/v1/push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/shared',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertCreated();

        expect($first->fresh()->user_id)->toBe($second->id);
    });

    it('rejects an insecure endpoint and missing keys', function () {
        actingAsRole(RoleName::Responder);

        $this->postJson('/api/v1/push/subscriptions', ['endpoint' => 'http://insecure.example/send', 'keys' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    });

    it('lists and removes only my browsers', function () {
        $me = actingAsRole(RoleName::Responder);
        $mine = PushSubscription::factory()->for($me)->create();
        $theirs = PushSubscription::factory()->create();

        $this->getJson('/api/v1/push/subscriptions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->deleteJson("/api/v1/push/subscriptions/{$theirs->id}")->assertNotFound();
        $this->deleteJson("/api/v1/push/subscriptions/{$mine->id}")->assertNoContent();
    });

    it('removes a browser by the endpoint it knows', function () {
        $me = actingAsRole(RoleName::Responder);
        $mine = PushSubscription::factory()->for($me)->create();
        PushSubscription::factory()->create();

        $this->deleteJson('/api/v1/push/subscriptions?endpoint='.urlencode($mine->endpoint))->assertNoContent();

        expect(PushSubscription::count())->toBe(1)
            ->and(PushSubscription::sole()->user_id)->not->toBe($me->id);
    });

    it('sends a test push to my browser', function () {
        $me = actingAsRole(RoleName::Responder);
        $subscription = PushSubscription::factory()->for($me)->create();
        $fake = new FakePushSender;
        $this->app->instance(PushService::class, new PushService($fake));

        $this->postJson("/api/v1/push/subscriptions/{$subscription->id}/test")
            ->assertOk()
            ->assertJsonPath('data.sent', true);

        expect($fake->sent)->toHaveCount(1)
            ->and($fake->sent[0]['payload']['title'])->toBe('Notifications are on');
    });
});

describe('preferences', function () {
    it('returns defaults and saves changes', function () {
        actingAsRole(RoleName::Responder);

        // Event keys contain dots, so compare the whole map rather than a dotted path.
        $this->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.channels.push', true)
            ->assertJsonPath('data.events', fn (array $events) => $events['sla.breached'] === ['in_app', 'push', 'email']);

        $this->putJson('/api/v1/me/notification-preferences', [
            'channels' => ['email' => false],
            'events' => ['incident.status_changed' => ['in_app']],
            'quiet_hours' => ['enabled' => true, 'start' => '22:00', 'end' => '06:00'],
        ])
            ->assertOk()
            ->assertJsonPath('data.channels.email', false)
            ->assertJsonPath('data.events', fn (array $events) => $events['incident.status_changed'] === ['in_app'])
            ->assertJsonPath('data.quiet_hours.enabled', true);
    });

    it('rejects an unknown channel and ignores an unknown event', function () {
        actingAsRole(RoleName::Responder);

        $this->putJson('/api/v1/me/notification-preferences', ['events' => ['incident.assigned' => ['carrier_pigeon']]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors) => array_key_exists('events.incident.assigned.0', $errors));

        $this->putJson('/api/v1/me/notification-preferences', ['events' => ['made.up.event' => ['in_app']]])
            ->assertOk()
            ->assertJsonPath('data.events', fn (array $events) => ! array_key_exists('made.up.event', $events));
    });
});

describe('the delivery log', function () {
    it('is for auditors only', function () {
        $manager = actingAsRole(RoleName::Admin);
        NotificationLog::create([
            'user_id' => $manager->id,
            'event' => NotificationEvent::SlaBreached,
            'channel' => 'push',
            'status' => 'sent',
            'title' => 'SLA breached: INC-2026-00001',
            'sent_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/notification-log')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'sent')
            ->assertJsonPath('data.0.event', 'sla.breached');

        Sanctum::actingAs(userWithRole(RoleName::Responder), ['*']);
        $this->getJson('/api/v1/admin/notification-log')->assertForbidden();
    });
});
