<?php

use App\Enums\EscalationTargetType;
use App\Enums\RoleName;
use App\Models\EscalationPolicy;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\RoutingRule;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;

it('tells a reporter which team, on-call person and escalation contacts an incident would reach', function () {
    actingAsRole(RoleName::Reporter);
    $payments = Team::factory()->create(['name' => 'Payments']);
    $onCall = User::factory()->create(['name' => 'Jane']);
    $lead = User::factory()->create(['name' => 'Lead']);
    $payments->addMember($onCall);
    $payments->addMember($lead, isLead: true);
    OnCallShift::factory()->for(OnCallSchedule::factory()->for($payments)->create(), 'schedule')->create(['user_id' => $onCall->id]);
    $policy = EscalationPolicy::factory()->create();
    $policy->levels()->createMany([
        ['level' => 1, 'after_minutes' => 0, 'target_type' => EscalationTargetType::OnCall],
        ['level' => 2, 'after_minutes' => 10, 'target_type' => EscalationTargetType::TeamLead],
    ]);
    $service = Service::factory()->for($payments, 'ownerTeam')->create();
    $rule = RoutingRule::factory()->create([
        'name' => 'Payments gateway', 'priority' => 10, 'service_id' => $service->id, 'team_id' => $payments->id,
        'escalation_policy_id' => $policy->id, 'notify_channel_ids' => [4],
    ]);

    $this->getJson("/api/v1/routing/who-to-report?service_id={$service->id}&severity=2")
        ->assertOk()
        ->assertExactJson(['data' => [
            'team' => ['id' => $payments->id, 'name' => 'Payments'],
            'assignee' => ['id' => $onCall->id, 'name' => 'Jane'],
            'on_call' => ['id' => $onCall->id, 'name' => 'Jane'],
            'rule' => ['id' => $rule->id, 'name' => 'Payments gateway'],
            'escalation_policy' => ['id' => $policy->id, 'name' => $policy->name],
            'escalation' => [
                ['level' => 1, 'after_minutes' => 0, 'target_type' => 'on_call', 'contacts' => [['id' => $onCall->id, 'name' => 'Jane']]],
                ['level' => 2, 'after_minutes' => 10, 'target_type' => 'team_lead', 'contacts' => [['id' => $lead->id, 'name' => 'Lead']]],
            ],
            'notify_channel_ids' => [4],
        ]]);
});

it('requires a severity', function () {
    actingAsRole(RoleName::Reporter);

    $this->getJson('/api/v1/routing/who-to-report')->assertUnprocessable()->assertJsonValidationErrors('severity');
});

it('lets incident managers simulate routing', function () {
    actingAsRole(RoleName::IncidentManager);
    $noc = Team::factory()->create(['name' => 'NOC']);

    $this->postJson('/api/v1/routing-rules/test', ['severity' => 3])
        ->assertOk()
        ->assertJsonPath('data.team.id', $noc->id)
        ->assertJsonPath('data.rule', null);
});

it('returns 403 when a responder simulates routing', function () {
    actingAsRole(RoleName::Responder);

    $this->postJson('/api/v1/routing-rules/test', ['severity' => 3])->assertForbidden();
});
