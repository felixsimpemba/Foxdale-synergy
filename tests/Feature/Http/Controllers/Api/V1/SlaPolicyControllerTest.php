<?php

use App\Enums\RoleName;
use App\Models\SlaPolicy;

it('lists the default targets for every severity', function () {
    actingAsRole(RoleName::Reporter);

    $this->getJson('/api/v1/sla-policies')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0', ['severity' => 1, 'ack_minutes' => 5, 'mitigate_minutes' => 30, 'resolve_minutes' => 240])
        ->assertJsonPath('data.3.resolve_minutes', 7 * 24 * 60);
});

it('lets an admin change a target, which applies to new incidents', function () {
    actingAsRole(RoleName::Admin);

    $this->putJson('/api/v1/admin/sla-policies/2', ['ack_minutes' => 10, 'mitigate_minutes' => 45, 'resolve_minutes' => 360])
        ->assertOk()
        ->assertJsonPath('data.ack_minutes', 10);

    expect(SlaPolicy::sole())->ack_minutes->toBe(10);
    $this->getJson('/api/v1/sla-policies')->assertJsonPath('data.1.ack_minutes', 10);
});

it('requires targets in increasing order', function () {
    actingAsRole(RoleName::Admin);

    $this->putJson('/api/v1/admin/sla-policies/2', ['ack_minutes' => 60, 'mitigate_minutes' => 30, 'resolve_minutes' => 20])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mitigate_minutes', 'resolve_minutes']);
});

it('returns 404 for an unknown severity', function () {
    actingAsRole(RoleName::Admin);

    $this->putJson('/api/v1/admin/sla-policies/9', ['ack_minutes' => 1, 'mitigate_minutes' => 2, 'resolve_minutes' => 3])->assertNotFound();
});

it('returns 403 for an incident manager', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->putJson('/api/v1/admin/sla-policies/2', ['ack_minutes' => 10, 'mitigate_minutes' => 45, 'resolve_minutes' => 360])->assertForbidden();
});
