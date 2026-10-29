<?php

use App\Enums\RoleName;
use App\Models\Team;

it('returns entries newest first, filtered by entity and action prefix', function () {
    $admin = actingAsRole(RoleName::Admin);
    $teamId = $this->postJson('/api/v1/teams', ['name' => 'Payments'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/teams/{$teamId}", ['name' => 'Payments Gateway'])->assertOk();
    Team::factory()->create();

    $response = $this->getJson("/api/v1/admin/audit-logs?entity_type=team&entity_id={$teamId}&action=team.")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'team.updated')
        ->assertJsonPath('data.0.user.id', $admin->id)
        ->assertJsonPath('data.0.source', 'api')
        ->assertJsonPath('data.1.action', 'team.created');

    expect($response->json('data.0.changes'))->toEqual([
        'before' => ['name' => 'Payments'],
        'after' => ['name' => 'Payments Gateway'],
    ]);
});

it('records changes made outside a request with source system', function () {
    actingAsRole(RoleName::Admin);
    Team::factory()->create();

    $this->getJson('/api/v1/admin/audit-logs?action=team.created')
        ->assertOk()
        ->assertJsonPath('data.0.source', 'system');
});

it('rejects a "to" date before "from"', function () {
    actingAsRole(RoleName::Admin);

    $this->getJson('/api/v1/admin/audit-logs?from=2026-10-10&to=2026-10-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

it('returns 403 without audit.view', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
});
