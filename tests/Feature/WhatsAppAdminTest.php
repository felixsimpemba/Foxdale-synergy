<?php

use App\Enums\RoleName;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'incidents.whatsapp.base_url' => 'https://wapi.test',
        'incidents.whatsapp.api_key' => 'test-key',
        'incidents.whatsapp.instance' => 'whatsapp-linked',
    ]);

    app(SettingsService::class)->update(['channels.whatsapp.enabled' => true]);
});

it('rejects people who cannot manage integrations', function () {
    Sanctum::actingAs(User::factory()->create(), ['*']);

    $this->getJson('/api/v1/admin/whatsapp')->assertForbidden();
});

it('returns the instance state', function () {
    actingAsRole(RoleName::Admin);
    Http::fake([
        'wapi.test/instance/connectionState/whatsapp-linked' => Http::response([
            'instance' => ['instanceName' => 'whatsapp-linked', 'state' => 'open'],
        ]),
    ]);

    $this->getJson('/api/v1/admin/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.instance', 'whatsapp-linked')
        ->assertJsonPath('data.state', 'open')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.configured', true);
});

it('reports a gateway error without failing the status request', function () {
    actingAsRole(RoleName::Admin);
    Http::fake([
        'wapi.test/instance/connectionState/whatsapp-linked' => Http::response(
            ['message' => 'Instance not found'],
            404,
        ),
    ]);

    $this->getJson('/api/v1/admin/whatsapp')
        ->assertOk()
        ->assertJsonPath('data.state', null)
        ->assertJsonPath('data.error', 'The WhatsApp gateway rejected the request: Instance not found');
});

it('updates the master switch and instance name', function () {
    actingAsRole(RoleName::Admin);
    Http::fake([
        'wapi.test/instance/connectionState/foxdale' => Http::response(['instance' => ['state' => 'close']]),
    ]);

    $this->putJson('/api/v1/admin/whatsapp', [
        'enabled' => false,
        'instance' => 'foxdale',
    ])
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.instance', 'foxdale');

    $settings = app(SettingsService::class);

    expect($settings->get('channels.whatsapp.enabled'))->toBeFalse()
        ->and($settings->get('whatsapp.instance'))->toBe('foxdale');
});

it('fetches groups and keeps the saved selection', function () {
    actingAsRole(RoleName::Admin);
    WhatsAppGroup::query()->create([
        'group_jid' => '120363410040073098@g.us',
        'subject' => 'Old name',
        'is_active' => true,
    ]);
    Http::fake([
        'wapi.test/group/fetchAllGroups/whatsapp-linked*' => Http::response([
            ['id' => '120363410040073098@g.us', 'subject' => 'Monitoring Reloaded', 'size' => 11],
            ['id' => '120363430743746881@g.us', 'subject' => 'Monitoring AI', 'size' => 4],
        ]),
    ]);

    $this->getJson('/api/v1/admin/whatsapp/groups')
        ->assertOk()
        ->assertJsonPath('data.synced', true)
        ->assertJsonCount(2, 'data.groups')
        ->assertJsonFragment([
            'group_jid' => '120363410040073098@g.us',
            'subject' => 'Monitoring Reloaded',
            'is_active' => true,
        ])
        ->assertJsonFragment([
            'group_jid' => '120363430743746881@g.us',
            'subject' => 'Monitoring AI',
            'is_active' => false,
        ]);

    expect(WhatsAppGroup::query()->count())->toBe(2);
});

it('falls back to the saved groups when the gateway is unreachable', function () {
    actingAsRole(RoleName::Admin);
    WhatsAppGroup::query()->create([
        'group_jid' => '120363410040073098@g.us',
        'subject' => 'Monitoring Reloaded',
        'is_active' => true,
    ]);
    Http::fake([
        'wapi.test/group/fetchAllGroups/whatsapp-linked*' => Http::response(['message' => 'down'], 500),
    ]);

    $this->getJson('/api/v1/admin/whatsapp/groups')
        ->assertOk()
        ->assertJsonPath('data.synced', false)
        ->assertJsonCount(1, 'data.groups')
        ->assertJsonPath('data.groups.0.group_jid', '120363410040073098@g.us');
});

it('saves which groups receive alerts', function () {
    actingAsRole(RoleName::Admin);

    $this->putJson('/api/v1/admin/whatsapp/groups', [
        'groups' => [
            [
                'group_jid' => '120363410040073098@g.us',
                'subject' => 'Monitoring Reloaded',
                'is_active' => true,
            ],
            [
                'group_jid' => '120363430743746881@g.us',
                'subject' => 'Monitoring AI',
                'is_active' => false,
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonCount(2, 'data.groups');

    expect(WhatsAppGroup::query()->where('group_jid', '120363410040073098@g.us')->first()?->is_active)->toBeTrue()
        ->and(WhatsAppGroup::query()->where('group_jid', '120363430743746881@g.us')->first()?->is_active)->toBeFalse();
});

it('creates an instance and makes it active', function () {
    actingAsRole(RoleName::Admin);
    Http::fake([
        'wapi.test/instance/create' => Http::response([
            'instance' => ['instanceName' => 'foxdale'],
            'hash' => 'instance-key',
        ], 201),
    ]);

    $this->postJson('/api/v1/admin/whatsapp/instance', ['instance' => 'foxdale'])
        ->assertCreated()
        ->assertJsonPath('data.instance', 'foxdale');

    expect(app(SettingsService::class)->get('whatsapp.instance'))->toBe('foxdale');
});

it('returns the linking QR code', function () {
    actingAsRole(RoleName::Admin);
    Http::fake([
        'wapi.test/instance/connect/whatsapp-linked' => Http::response([
            'base64' => 'data:image/png;base64,abc',
            'code' => '2@abc',
        ]),
    ]);

    $this->getJson('/api/v1/admin/whatsapp/connect')
        ->assertOk()
        ->assertJsonPath('data.base64', 'data:image/png;base64,abc')
        ->assertJsonPath('data.code', '2@abc');
});

it('sends a test message to a number', function () {
    actingAsRole(RoleName::Admin);
    Http::fake([
        'wapi.test/message/sendText/whatsapp-linked' => Http::response(['key' => ['id' => 'abc']]),
    ]);

    $this->postJson('/api/v1/admin/whatsapp/test', [
        'number' => '+260973419876',
        'text' => 'test from the app',
    ])
        ->assertOk()
        ->assertJsonPath('data.sent', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://wapi.test/message/sendText/whatsapp-linked'
        && $request['number'] === '260973419876'
        && $request['text'] === 'test from the app'
        && $request->hasHeader('apikey', 'test-key'));
});

it('sends a test message to a group', function () {
    actingAsRole(RoleName::Admin);
    Http::fake([
        'wapi.test/message/sendText/whatsapp-linked' => Http::response(['key' => ['id' => 'abc']]),
    ]);

    $this->postJson('/api/v1/admin/whatsapp/test', [
        'group_jid' => '120363410040073098@g.us',
    ])->assertOk();

    Http::assertSent(fn ($request): bool => $request['number'] === '120363410040073098@g.us');
});
