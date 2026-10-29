<?php

use App\Enums\RoleName;
use App\Models\Service;
use App\Models\Team;

describe('index and show', function () {
    it('filters services by owner team and active state', function () {
        actingAsRole(RoleName::Reporter);
        $payments = Team::factory()->create();
        $gateway = Service::factory()->for($payments, 'ownerTeam')->create(['name' => 'Payments gateway']);
        Service::factory()->for($payments, 'ownerTeam')->create(['is_active' => false]);
        Service::factory()->create();

        $this->getJson("/api/v1/services?owner_team_id={$payments->id}&is_active=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gateway->id)
            ->assertJsonPath('data.0.owner_team.id', $payments->id);
    });

    it('shows one service', function () {
        actingAsRole(RoleName::Reporter);
        $service = Service::factory()->create(['criticality' => 'critical']);

        $this->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.criticality', 'critical');
    });
});

describe('store and update', function () {
    it('creates a service with default criticality and active state', function () {
        actingAsRole(RoleName::Admin);
        $team = Team::factory()->create();

        $this->postJson('/api/v1/services', [
            'name' => 'Payments gateway',
            'owner_team_id' => $team->id,
            'criticality' => 'critical',
            'runbook_url' => 'https://wiki.example.com/runbooks/payments',
        ])->assertCreated()
            ->assertJsonPath('data.owner_team.id', $team->id)
            ->assertJsonPath('data.is_active', true);
    });

    it('rejects an unknown criticality and a non-http runbook URL', function () {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/services', [
            'name' => 'Payments gateway',
            'criticality' => 'apocalyptic',
            'runbook_url' => 'javascript:alert(1)',
        ])->assertUnprocessable()->assertJsonValidationErrors(['criticality', 'runbook_url']);
    });

    it('deactivates a service', function () {
        actingAsRole(RoleName::Admin);
        $service = Service::factory()->create();

        $this->putJson("/api/v1/services/{$service->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('returns 403 for a responder', function () {
        actingAsRole(RoleName::Responder);

        $this->postJson('/api/v1/services', ['name' => 'x', 'criticality' => 'low'])->assertForbidden();
    });
});
