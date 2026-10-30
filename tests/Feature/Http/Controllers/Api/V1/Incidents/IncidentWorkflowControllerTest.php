<?php

use App\Enums\EscalationTargetType;
use App\Enums\IncidentLinkType;
use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Enums\Severity;
use App\Events\IncidentEscalated;
use App\Events\IncidentStatusChanged;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\IncidentResolution;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

function transitionTo(Incident $incident, string $status, array $extra = []): TestResponse
{
    return test()->postJson("/api/v1/incidents/{$incident->id}/transition", ['status' => $status, ...$extra]);
}

describe('lifecycle', function () {
    it('goes from reported to closed with every stage timed', function () {
        $this->freezeTime();
        $manager = actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->severity(Severity::Sev3)->create();

        $this->travel(4)->minutes();
        $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged')
            ->assertJsonPath('data.routing.assignee.id', $manager->id)
            ->assertJsonPath('data.timings.time_to_ack', 240);

        $this->travel(1)->minutes();
        transitionTo($incident, 'investigating')->assertOk();

        $this->travel(10)->minutes();
        transitionTo($incident, 'mitigated', ['note' => 'Restarted the consumer'])
            ->assertOk()
            ->assertJsonPath('data.timings.time_to_mitigate', 15 * 60);

        $this->travel(45)->minutes();
        transitionTo($incident, 'resolved', [
            'resolution' => ['summary' => 'Queue consumer reconnects with backoff', 'fix_type' => 'code_change'],
        ])->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.timings.time_to_resolve', 60 * 60);

        IncidentResolution::sole()->update(['verification' => 'Deposits succeeded for 30 minutes']);

        transitionTo($incident, 'closed')->assertOk()->assertJsonPath('data.status', 'closed');

        $history = $incident->statusHistory()->orderBy('id')->get();
        expect($history->pluck('to_status')->map->value->all())
            ->toBe(['reported', 'acknowledged', 'investigating', 'mitigated', 'resolved', 'closed'])
            ->and($history->pluck('seconds_in_previous')->all())->toBe([null, 240, 60, 600, 2700, 0]);
    });

    it('dispatches IncidentStatusChanged with both statuses', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Acknowledged)->create();
        Event::fake([IncidentStatusChanged::class]);

        transitionTo($incident, 'investigating')->assertOk();

        Event::assertDispatched(IncidentStatusChanged::class, fn (IncidentStatusChanged $event) => $event->from === IncidentStatus::Acknowledged
            && $event->to === IncidentStatus::Investigating);
    });
});

describe('transition rules', function () {
    it('allows the transitions in the specification', function (IncidentStatus $from, string $to, array $extra) {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->inStatus($from)->create();
        IncidentResolution::factory()->for($incident)->create();

        transitionTo($incident, $to, $extra)->assertOk()->assertJsonPath('data.status', $to);
    })->with([
        'reported to acknowledged' => [IncidentStatus::Reported, 'acknowledged', []],
        'acknowledged to investigating' => [IncidentStatus::Acknowledged, 'investigating', []],
        'investigating to mitigated' => [IncidentStatus::Investigating, 'mitigated', ['note' => 'Failover done']],
        'investigating to resolved' => [IncidentStatus::Investigating, 'resolved', []],
        'mitigated to resolved' => [IncidentStatus::Mitigated, 'resolved', []],
        'resolved to closed' => [IncidentStatus::Resolved, 'closed', []],
        'resolved to investigating' => [IncidentStatus::Resolved, 'investigating', ['note' => 'Errors are back']],
        'closed to investigating' => [IncidentStatus::Closed, 'investigating', ['note' => 'Errors are back']],
        'reported to cancelled' => [IncidentStatus::Reported, 'cancelled', ['note' => 'False alarm']],
        'acknowledged to cancelled' => [IncidentStatus::Acknowledged, 'cancelled', ['note' => 'False alarm']],
    ]);

    it('returns 422 invalid_transition for any other move', function (IncidentStatus $from, string $to) {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->inStatus($from)->create();

        transitionTo($incident, $to, ['note' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_transition');

        expect($incident->fresh()->status)->toBe($from);
    })->with([
        'reported to investigating' => [IncidentStatus::Reported, 'investigating'],
        'reported to resolved' => [IncidentStatus::Reported, 'resolved'],
        'acknowledged to resolved' => [IncidentStatus::Acknowledged, 'resolved'],
        'investigating to cancelled' => [IncidentStatus::Investigating, 'cancelled'],
        'mitigated to investigating' => [IncidentStatus::Mitigated, 'investigating'],
        'resolved to mitigated' => [IncidentStatus::Resolved, 'mitigated'],
        'closed to resolved' => [IncidentStatus::Closed, 'resolved'],
        'cancelled to acknowledged' => [IncidentStatus::Cancelled, 'acknowledged'],
        'investigating to reported' => [IncidentStatus::Investigating, 'reported'],
    ]);

    it('requires a note to mitigate', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Investigating)->create();

        transitionTo($incident, 'mitigated')
            ->assertUnprocessable()
            ->assertJsonPath('errors.note.0', 'Describe what reduced the impact.');
    });

    it('requires a resolution summary and fix type to resolve', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Investigating)->create();

        transitionTo($incident, 'resolved')->assertUnprocessable()->assertJsonValidationErrors('resolution.summary');
        transitionTo($incident, 'resolved', ['resolution' => ['summary' => 'Fixed']])->assertUnprocessable()->assertJsonValidationErrors('resolution.fix_type');
    });

    it('stops running timers when the incident is resolved', function () {
        $this->freezeTime();
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Investigating)->create();
        $timer = TimeEntry::factory()->for($incident)->running()->create();

        $this->travel(20)->minutes();
        transitionTo($incident, 'resolved', ['resolution' => ['summary' => 'Fixed', 'fix_type' => 'rollback']])->assertOk();

        expect($timer->fresh())->isRunning()->toBeFalse()->minutes->toBe(20);
    });
});

describe('closing', function () {
    it('returns 422 rca_required for SEV1 and SEV2', function (Severity $severity) {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->severity($severity)->inStatus(IncidentStatus::Resolved)->create();
        IncidentResolution::factory()->for($incident)->create();

        transitionTo($incident, 'closed')->assertUnprocessable()->assertJsonPath('code', 'rca_required');
    })->with([Severity::Sev1, Severity::Sev2]);

    it('returns 422 resolution_incomplete for SEV3 without verification', function () {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->severity(Severity::Sev3)->inStatus(IncidentStatus::Resolved)->create();
        IncidentResolution::factory()->for($incident)->withoutVerification()->create();

        transitionTo($incident, 'closed')->assertUnprocessable()->assertJsonPath('code', 'resolution_incomplete');
    });

    it('returns 403 for NOC, who cannot close', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Resolved)->create();

        transitionTo($incident, 'closed')->assertForbidden();
    });

    it('returns 403 for a personal token even with incidents:update', function () {
        Sanctum::actingAs(userWithRole(RoleName::IncidentManager), ['incidents:update']);
        $incident = Incident::factory()->inStatus(IncidentStatus::Resolved)->create();
        IncidentResolution::factory()->for($incident)->create();

        transitionTo($incident, 'closed')->assertForbidden();
    });
});

describe('reopen and cancel', function () {
    it('reopens a resolved incident, counts the recurrence and clears resolved_at', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Resolved)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/reopen", ['reason' => 'Errors are back'])
            ->assertOk()
            ->assertJsonPath('data.status', 'investigating')
            ->assertJsonPath('data.reopened_count', 1)
            ->assertJsonPath('data.resolved_at', null);
    });

    it('returns 422 invalid_transition when reopening an open incident', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->inStatus(IncidentStatus::Acknowledged)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/reopen", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_transition');
    });

    it('cancels a duplicate and links it to the original', function () {
        actingAsRole(RoleName::Noc);
        $original = Incident::factory()->create();
        $duplicate = Incident::factory()->create();

        transitionTo($duplicate, 'cancelled', ['note' => 'Duplicate', 'duplicate_of_id' => $original->id])->assertOk();

        expect($duplicate->links()->sole())
            ->related_incident_id->toBe($original->id)
            ->type->toBe(IncidentLinkType::Duplicate);
    });

    it('requires a reason to cancel', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        transitionTo($incident, 'cancelled')->assertUnprocessable()->assertJsonValidationErrors('note');
    });
});

describe('responder permissions', function () {
    it('lets a responder acknowledge a team incident and take ownership', function () {
        $responder = actingAsRole(RoleName::Responder);
        $team = Team::factory()->create();
        $team->addMember($responder);
        $incident = Incident::factory()->forTeam($team)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.assignee_id', $responder->id);
    });

    it('returns 403 when a responder moves an incident assigned to someone else', function () {
        $responder = actingAsRole(RoleName::Responder);
        $team = Team::factory()->create();
        $team->addMember($responder);
        $incident = Incident::factory()->forTeam($team)->assignedTo(User::factory()->create())
            ->inStatus(IncidentStatus::Acknowledged)->create();

        transitionTo($incident, 'investigating')->assertForbidden();
    });

    it('lets a responder move an incident assigned to them', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->inStatus(IncidentStatus::Acknowledged)->create();

        transitionTo($incident, 'investigating')->assertOk();
    });

    it('returns 404 when a reporter tries to acknowledge someone else\'s incident', function () {
        actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge")->assertNotFound();
    });

    it('returns 403 when a reporter acknowledges their own incident', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create(['reporter_id' => $reporter->id]);

        $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge")->assertForbidden();
    });
});

describe('assign', function () {
    it('reassigns to another user with a reason', function () {
        $noc = actingAsRole(RoleName::Noc);
        $from = User::factory()->create();
        $to = User::factory()->create();
        $incident = Incident::factory()->assignedTo($from)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assignee_id' => $to->id, 'reason' => 'Database expertise'])
            ->assertOk()
            ->assertJsonPath('data.routing.assignee.id', $to->id);

        expect($incident->assignments()->latest('id')->first())
            ->from_user_id->toBe($from->id)
            ->to_user_id->toBe($to->id)
            ->assigned_by->toBe($noc->id)
            ->reason->toBe('Database expertise');
    });

    it('rejects a deactivated assignee', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assignee_id' => User::factory()->inactive()->create()->id, 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id');
    });

    it('returns 403 for a responder', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/assign", ['assignee_id' => $responder->id, 'reason' => 'x'])->assertForbidden();
    });
});

describe('severity', function () {
    it('recalculates the SLA deadlines and notes the change on the timeline', function () {
        $this->freezeTime();
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->severity(Severity::Sev3)->create();

        $this->putJson("/api/v1/incidents/{$incident->id}/severity", ['severity' => 1, 'reason' => 'All deposits failing'])
            ->assertOk()
            ->assertJsonPath('data.severity', 1)
            ->assertJsonPath('data.sla.ack_due', $incident->reported_at->copy()->addMinutes(5)->toIso8601ZuluString())
            ->assertJsonPath('data.sla.resolve_due', $incident->reported_at->copy()->addHours(4)->toIso8601ZuluString());

        expect($incident->updates()->sole()->body)->toBe('Severity changed from SEV3 to SEV1: All deposits failing');
    });

    it('returns 403 for a responder', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->putJson("/api/v1/incidents/{$incident->id}/severity", ['severity' => 1, 'reason' => 'x'])->assertForbidden();
    });
});

describe('escalate', function () {
    it('notifies the next level now', function () {
        actingAsRole(RoleName::Noc);
        $team = Team::factory()->create();
        $lead = User::factory()->create();
        $team->addMember($lead, isLead: true);
        $policy = EscalationPolicy::factory()->create();
        $policy->levels()->createMany([
            ['level' => 1, 'after_minutes' => 0, 'target_type' => EscalationTargetType::OnCall],
            ['level' => 2, 'after_minutes' => 10, 'target_type' => EscalationTargetType::TeamLead],
        ]);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'escalation_policy_id' => $policy->id, 'escalation_level' => 1]);
        Event::fake([IncidentEscalated::class]);

        $this->postJson("/api/v1/incidents/{$incident->id}/escalate")
            ->assertOk()
            ->assertJsonPath('data.routing.escalation_level', 2)
            ->assertJsonPath('data.routing.escalates_at', null);

        Event::assertDispatched(IncidentEscalated::class, fn (IncidentEscalated $event) => $event->recipients->modelKeys() === [$lead->id]);
    });

    it('returns 422 no_escalation_policy without a policy', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/escalate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'no_escalation_policy');
    });

    it('returns 422 no_further_escalation at the last level', function () {
        actingAsRole(RoleName::Noc);
        $policy = EscalationPolicy::factory()->create();
        $policy->levels()->create(['level' => 1, 'after_minutes' => 0, 'target_type' => EscalationTargetType::OnCall]);
        $incident = Incident::factory()->create(['escalation_policy_id' => $policy->id, 'escalation_level' => 1]);

        $this->postJson("/api/v1/incidents/{$incident->id}/escalate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'no_further_escalation');
    });

    it('returns 403 for a responder', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/escalate")->assertForbidden();
    });
});
