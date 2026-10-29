<?php

use App\Enums\RoleName;
use App\Models\EscalationPolicy;
use App\Models\User;
use Spatie\Permission\Models\Role;

function standardLevels(): array
{
    return [
        ['level' => 1, 'after_minutes' => 0, 'target_type' => 'on_call'],
        ['level' => 2, 'after_minutes' => 10, 'target_type' => 'team_lead'],
        ['level' => 3, 'after_minutes' => 20, 'target_type' => 'role', 'target_id' => Role::findByName('incident_manager', 'web')->id],
    ];
}

it('creates a policy with its levels', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->postJson('/api/v1/escalation-policies', ['name' => 'Standard', 'levels' => standardLevels()])
        ->assertCreated()
        ->assertJsonCount(3, 'data.levels')
        ->assertJsonPath('data.levels.2.target_type', 'role');
});

it('rejects levels whose timing does not increase', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->postJson('/api/v1/escalation-policies', ['name' => 'Bad', 'levels' => [
        ['level' => 1, 'after_minutes' => 10, 'target_type' => 'on_call'],
        ['level' => 2, 'after_minutes' => 5, 'target_type' => 'team_lead'],
    ]])->assertUnprocessable()
        ->assertJsonPath('errors.levels.0', 'Each level must start later than the level before it.');
});

it('requires an existing target for user and role levels', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->postJson('/api/v1/escalation-policies', ['name' => 'Bad', 'levels' => [
        ['level' => 1, 'after_minutes' => 0, 'target_type' => 'user'],
        ['level' => 2, 'after_minutes' => 10, 'target_type' => 'role', 'target_id' => 999999],
    ]])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'levels.0.target_id' => 'A user target needs a target_id.',
            'levels.1.target_id' => 'The selected target does not exist.',
        ]);
});

it('replaces the levels on update', function () {
    actingAsRole(RoleName::IncidentManager);
    $policy = EscalationPolicy::factory()->withStandardLevels(Role::findByName('incident_manager', 'web')->id)->create();

    $this->putJson("/api/v1/escalation-policies/{$policy->id}", ['levels' => [
        ['level' => 1, 'after_minutes' => 0, 'target_type' => 'user', 'target_id' => User::factory()->create()->id],
    ]])->assertOk()->assertJsonCount(1, 'data.levels');

    expect($policy->levels()->count())->toBe(1);
});

it('returns 403 for NOC', function () {
    actingAsRole(RoleName::Noc);

    $this->postJson('/api/v1/escalation-policies', ['name' => 'Standard', 'levels' => standardLevels()])->assertForbidden();
});
