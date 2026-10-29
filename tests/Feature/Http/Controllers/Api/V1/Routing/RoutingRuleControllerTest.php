<?php

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\RoutingRule;
use App\Models\Team;

it('lists rules in evaluation order', function () {
    actingAsRole(RoleName::IncidentManager);
    RoutingRule::factory()->create(['priority' => 99, 'name' => 'Default']);
    RoutingRule::factory()->create(['priority' => 10, 'name' => 'Payments']);

    $this->getJson('/api/v1/routing-rules')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Payments')
        ->assertJsonPath('data.1.name', 'Default');
});

it('creates a rule', function () {
    actingAsRole(RoleName::IncidentManager);
    $dba = Team::factory()->create();
    $database = Category::factory()->create();

    $this->postJson('/api/v1/routing-rules', [
        'name' => 'Database issues to DBA',
        'priority' => 20,
        'category_id' => $database->id,
        'team_id' => $dba->id,
        'assignment_mode' => 'on_call',
    ])->assertCreated()
        ->assertJsonPath('data.team_id', $dba->id)
        ->assertJsonPath('data.routes_to_service_owner', false)
        ->assertJsonPath('data.min_severity', null);
});

it('rejects a severity range that ends before it starts and an unknown mode', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->postJson('/api/v1/routing-rules', [
        'name' => 'x', 'priority' => 10, 'min_severity' => 3, 'max_severity' => 1, 'assignment_mode' => 'random',
    ])->assertUnprocessable()->assertJsonValidationErrors(['max_severity', 'assignment_mode']);
});

it('updates and deletes a rule', function () {
    actingAsRole(RoleName::IncidentManager);
    $rule = RoutingRule::factory()->create();

    $this->putJson("/api/v1/routing-rules/{$rule->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->deleteJson("/api/v1/routing-rules/{$rule->id}")->assertNoContent();

    $this->assertModelMissing($rule);
});

it('returns 403 for NOC', function () {
    actingAsRole(RoleName::Noc);

    $this->getJson('/api/v1/routing-rules')->assertForbidden();
});
