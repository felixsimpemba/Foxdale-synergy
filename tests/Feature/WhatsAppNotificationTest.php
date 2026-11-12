<?php

use App\Enums\IncidentStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEvent;
use App\Enums\Severity;
use App\Events\UpdatePosted;
use App\Jobs\SendWhatsAppMessage;
use App\Listeners\NotifyOnIncidentUpdate;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'incidents.whatsapp.base_url' => 'https://wapi.test',
        'incidents.whatsapp.api_key' => 'test-key',
        'incidents.whatsapp.instance' => 'whatsapp-linked',
    ]);

    app(SettingsService::class)->update([
        'channels.whatsapp.enabled' => true,
        'whatsapp.instance' => 'whatsapp-linked',
    ]);
});

/**
 * @return array{title: string, body: string, url: string}
 */
function whatsAppContent(): array
{
    return [
        'title' => 'Assigned to you: INC-1',
        'body' => 'Deposits are failing',
        'url' => 'https://app.test/incidents/1',
    ];
}

it('queues a text to a user with a phone number', function () {
    Queue::fake();
    $user = User::factory()->create(['phone' => '+260973419876']);

    app(NotificationService::class)->sendTo(
        $user,
        NotificationEvent::IncidentAssigned,
        null,
        whatsAppContent(),
    );

    Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => $job->number === '260973419876'
        && str_contains($job->text, 'Deposits are failing')
        && str_contains($job->text, 'https://app.test/incidents/1'));

    expect(NotificationLog::query()
        ->where('channel', NotificationChannel::WhatsApp)
        ->where('status', NotificationDeliveryStatus::Queued)
        ->exists())->toBeTrue();
});

it('skips a user without a phone number and records why', function () {
    Queue::fake();
    $user = User::factory()->create(['phone' => null]);

    app(NotificationService::class)->sendTo(
        $user,
        NotificationEvent::IncidentAssigned,
        null,
        whatsAppContent(),
    );

    Queue::assertNothingPushed();

    expect(NotificationLog::query()
        ->where('channel', NotificationChannel::WhatsApp)
        ->where('status', NotificationDeliveryStatus::Skipped)
        ->where('error', 'like', '%phone%')
        ->exists())->toBeTrue();
});

it('does nothing when WhatsApp is switched off', function () {
    Queue::fake();
    app(SettingsService::class)->update(['channels.whatsapp.enabled' => false]);
    $user = User::factory()->create(['phone' => '+260973419876']);

    app(NotificationService::class)->sendTo(
        $user,
        NotificationEvent::IncidentAssigned,
        null,
        whatsAppContent(),
    );

    Queue::assertNothingPushed();
    expect(NotificationLog::query()->where('channel', NotificationChannel::WhatsApp)->exists())->toBeFalse();
});

it('broadcasts incident alerts to active groups once', function () {
    Queue::fake();
    WhatsAppGroup::query()->create([
        'group_jid' => '120363410040073098@g.us',
        'subject' => 'Monitoring Reloaded',
        'is_active' => true,
    ]);
    WhatsAppGroup::query()->create([
        'group_jid' => '120363430743746881@g.us',
        'subject' => 'Monitoring AI',
        'is_active' => false,
    ]);
    $incident = Incident::factory()->create();
    $notifications = app(NotificationService::class);
    $content = whatsAppContent();

    $notifications->send([], NotificationEvent::IncidentCreated, $incident, $content);
    $notifications->send([], NotificationEvent::IncidentCreated, $incident, $content);

    Queue::assertPushed(SendWhatsAppMessage::class, 1);
    Queue::assertPushed(
        SendWhatsAppMessage::class,
        fn (SendWhatsAppMessage $job): bool => $job->number === '120363410040073098@g.us',
    );

    expect(NotificationLog::query()
        ->whereNull('user_id')
        ->where('channel', NotificationChannel::WhatsApp)
        ->count())->toBe(1);
});

it('includes the incident details in group messages', function () {
    Queue::fake();
    WhatsAppGroup::factory()->active()->create(['group_jid' => '120363410040073098@g.us']);
    $incident = Incident::factory()
        ->severity(Severity::Sev2)
        ->create(['status' => IncidentStatus::Acknowledged]);
    $url = 'https://app.test/incidents/'.$incident->id;

    app(NotificationService::class)->send([], NotificationEvent::IncidentAssigned, $incident, [
        'title' => "Assigned to you: {$incident->ref}",
        'body' => $incident->title,
        'url' => $url,
    ]);

    Queue::assertPushed(
        SendWhatsAppMessage::class,
        fn (SendWhatsAppMessage $job): bool => str_contains($job->text, "Incident assigned: {$incident->ref}")
            && str_contains($job->text, 'SEV2')
            && str_contains($job->text, 'Acknowledged')
            && str_contains($job->text, $url),
    );
});

it('posts a Send to chat update to the active groups', function () {
    Queue::fake();
    WhatsAppGroup::factory()->active()->create(['group_jid' => '120363410040073098@g.us']);
    $author = User::factory()->create(['name' => 'Jane Banda']);
    $incident = Incident::factory()->create();
    $update = IncidentUpdate::factory()->for($incident)->create([
        'user_id' => $author->id,
        'body' => 'We found the cause in the payment gateway.',
    ]);

    (new NotifyOnIncidentUpdate(app(NotificationService::class)))
        ->handle(new UpdatePosted($update, true));

    Queue::assertPushed(
        SendWhatsAppMessage::class,
        fn (SendWhatsAppMessage $job): bool => str_contains($job->text, 'Jane Banda: We found the cause in the payment gateway.')
            && str_contains($job->text, $incident->ref)
            && str_contains($job->text, "/incidents/{$incident->id}"),
    );
});

it('does not post updates without the send-to-chat flag', function () {
    Queue::fake();
    WhatsAppGroup::factory()->active()->create();

    (new NotifyOnIncidentUpdate(app(NotificationService::class)))
        ->handle(new UpdatePosted(IncidentUpdate::factory()->create(), false));

    Queue::assertNothingPushed();
});

it('does not broadcast events that are personal only', function () {
    Queue::fake();
    WhatsAppGroup::query()->create([
        'group_jid' => '120363410040073098@g.us',
        'subject' => 'Monitoring Reloaded',
        'is_active' => true,
    ]);
    $incident = Incident::factory()->create();

    app(NotificationService::class)->send([], NotificationEvent::TaskAssigned, $incident, whatsAppContent());

    Queue::assertNothingPushed();
});

it('marks the log sent when the gateway accepts the message', function () {
    Http::fake([
        'wapi.test/message/sendText/whatsapp-linked' => Http::response(['key' => ['id' => 'abc']]),
    ]);

    $log = NotificationLog::query()->create([
        'event' => NotificationEvent::IncidentAssigned,
        'channel' => NotificationChannel::WhatsApp,
        'status' => NotificationDeliveryStatus::Queued,
        'title' => 'Assigned',
    ]);

    (new SendWhatsAppMessage('260973419876', 'hello', $log->id))->handle(app(WhatsAppClient::class));

    expect($log->fresh()->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($log->fresh()->sent_at)->not->toBeNull();
});

it('marks the log failed when every attempt fails', function () {
    $log = NotificationLog::query()->create([
        'event' => NotificationEvent::IncidentAssigned,
        'channel' => NotificationChannel::WhatsApp,
        'status' => NotificationDeliveryStatus::Queued,
        'title' => 'Assigned',
    ]);

    (new SendWhatsAppMessage('260973419876', 'hello', $log->id))
        ->failed(new RuntimeException('The gateway is down.'));

    expect($log->fresh()->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($log->fresh()->error)->toBe('The gateway is down.');
});
