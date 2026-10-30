<?php

use App\Enums\RoleName;
use App\Models\ApiKey;
use App\Models\AuditLog;

describe('store', function () {
    it('returns the key once and stores only its SHA-256 hash', function () {
        $admin = actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/v1/admin/api-keys', [
            'name' => 'Grafana production',
            'source' => 'grafana',
            'scopes' => ['incidents:create'],
            'ip_allowlist' => ['10.0.0.5'],
            'with_signing_secret' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.source', 'grafana')
            ->assertJsonPath('data.has_signing_secret', true)
            ->assertJsonMissingPath('data.key_hash')
            ->assertJsonStructure(['key', 'signing_secret']);

        $plainKey = $response->json('key');
        $apiKey = ApiKey::findActiveByPlainKey($plainKey);

        expect($apiKey)->not->toBeNull()
            ->and($apiKey->key_hash)->toBe(hash('sha256', $plainKey))
            ->and($apiKey->created_by)->toBe($admin->id)
            ->and($apiKey->signing_secret)->toBe($response->json('signing_secret'));

        $log = AuditLog::firstWhere('action', 'api_key.created');
        expect(json_encode($log->changes))->not->toContain($apiKey->key_hash);
    });

    it('rejects scopes other than incident create and update', function () {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/admin/api-keys', ['name' => 'x', 'source' => 'zabbix', 'scopes' => ['user.manage']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scopes.0');
    });

    it('returns 403 for an incident manager', function () {
        actingAsRole(RoleName::IncidentManager);

        $this->postJson('/api/v1/admin/api-keys', ['name' => 'x', 'source' => 'zabbix', 'scopes' => ['incidents:create']])
            ->assertForbidden();
    });
});

it('lists active keys without secrets', function () {
    actingAsRole(RoleName::Admin);
    ApiKey::factory()->create(['name' => 'active']);
    ApiKey::factory()->create(['name' => 'old', 'revoked_at' => now()]);

    $this->getJson('/api/v1/admin/api-keys')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'active')
        ->assertJsonMissingPath('data.0.key_hash');
});

it('rotates a key so the old key stops working', function () {
    actingAsRole(RoleName::Admin);
    $apiKey = ApiKey::factory()->create();
    $oldHash = $apiKey->key_hash;

    $newKey = $this->postJson("/api/v1/admin/api-keys/{$apiKey->id}/rotate")->assertOk()->json('key');

    expect($apiKey->fresh()->key_hash)->not->toBe($oldHash)
        ->and(ApiKey::findActiveByPlainKey($newKey)?->is($apiKey))->toBeTrue();
    $this->assertDatabaseHas(AuditLog::class, ['action' => 'api_key.rotated', 'entity_id' => $apiKey->id]);
});

it('revokes a key', function () {
    actingAsRole(RoleName::Admin);
    $apiKey = ApiKey::factory()->create();

    $this->deleteJson("/api/v1/admin/api-keys/{$apiKey->id}")->assertNoContent();

    expect($apiKey->fresh()->isRevoked())->toBeTrue();
});

it('returns 403 when rotating a revoked key', function () {
    actingAsRole(RoleName::Admin);
    $apiKey = ApiKey::factory()->create(['revoked_at' => now()]);

    $this->postJson("/api/v1/admin/api-keys/{$apiKey->id}/rotate")->assertForbidden();
});
