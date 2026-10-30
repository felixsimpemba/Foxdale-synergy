<?php

use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Enums\Severity;
use App\Events\IncidentCreated;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Incident;
use App\Models\RoutingRule;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InAppAlert;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

function incidentPayload(array $overrides = []): array
{
    return [
        'title' => 'Deposits failing on payment gateway',
        'description' => 'Callbacks time out since 08:55',
        'service_id' => Service::factory()->create()->id,
        'category_id' => Category::factory()->create()->id,
        'severity' => 2,
        'impact' => 'Customers cannot complete deposits',
        ...$overrides,
    ];
}

describe('store', function () {
    it('creates the incident, routes it to the service owner team and starts the SLA clocks', function () {
        $this->freezeTime();
        $reporter = actingAsRole(RoleName::Reporter);
        $payments = Team::factory()->create(['name' => 'Payments']);
        $service = Service::factory()->for($payments, 'ownerTeam')->create();
        // A catch-all rule without a team routes to the service's owner team.
        RoutingRule::factory()->create(['team_id' => null, 'priority' => 99]);

        $response = $this->postJson('/api/v1/incidents', incidentPayload(['service_id' => $service->id, 'severity' => 2]));

        $incident = Incident::sole();
        $response->assertCreated()
            ->assertJsonPath('data.ref', sprintf('INC-%d-%05d', now()->year, $incident->id))
            ->assertJsonPath('data.status', 'reported')
            ->assertJsonPath('data.severity', 2)
            ->assertJsonPath('data.routing.team', ['id' => $payments->id, 'name' => 'Payments'])
            ->assertJsonPath('data.reporter.id', $reporter->id)
            ->assertJsonPath('data.sla.ack_due', now()->addMinutes(15)->toIso8601ZuluString())
            ->assertJsonPath('data.sla.mitigate_due', now()->addHour()->toIso8601ZuluString())
            ->assertJsonPath('data.sla.resolve_due', now()->addHours(8)->toIso8601ZuluString());

        expect($incident->statusHistory()->sole()->to_status)->toBe(IncidentStatus::Reported)
            ->and($incident->assignments()->sole()->team_id)->toBe($payments->id);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'incident.created', 'entity_id' => $incident->id]);
    });

    it('routes to the default NOC team when no routing rule matches', function () {
        actingAsRole(RoleName::Reporter);
        $noc = Team::factory()->create(['name' => 'NOC']);
        $service = Service::factory()->create();

        $this->postJson('/api/v1/incidents', incidentPayload(['service_id' => $service->id]))
            ->assertCreated()
            ->assertJsonPath('data.routing.team.id', $noc->id);
    });

    it('dispatches IncidentCreated', function () {
        actingAsRole(RoleName::Reporter);
        $payload = incidentPayload();
        Event::fake([IncidentCreated::class]);

        $this->postJson('/api/v1/incidents', $payload)->assertCreated();

        Event::assertDispatched(IncidentCreated::class, fn (IncidentCreated $event) => $event->incident->is(Incident::sole()));
    });

    it('returns 422 with every required field', function () {
        actingAsRole(RoleName::Reporter);

        $this->postJson('/api/v1/incidents', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'service_id', 'category_id', 'severity']);
    });

    it('rejects a severity outside 1 to 4 and an inactive service', function () {
        actingAsRole(RoleName::Reporter);
        $inactive = Service::factory()->create(['is_active' => false]);

        $this->postJson('/api/v1/incidents', incidentPayload(['severity' => 5, 'service_id' => $inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['severity', 'service_id']);
    });

    it('replays the first response for a repeated Idempotency-Key', function () {
        actingAsRole(RoleName::Reporter);
        $payload = incidentPayload();

        $first = $this->postJson('/api/v1/incidents', $payload, ['Idempotency-Key' => 'retry-123'])->assertCreated();
        $second = $this->postJson('/api/v1/incidents', $payload, ['Idempotency-Key' => 'retry-123'])->assertCreated();

        expect(Incident::count())->toBe(1)
            ->and($second->json('data.id'))->toBe($first->json('data.id'))
            ->and($second->headers->get('Idempotent-Replayed'))->toBe('true');
    });

    it('returns 422 when an Idempotency-Key is reused with a different body', function () {
        actingAsRole(RoleName::Reporter);

        $this->postJson('/api/v1/incidents', incidentPayload(), ['Idempotency-Key' => 'retry-123'])->assertCreated();

        $this->postJson('/api/v1/incidents', incidentPayload(['title' => 'Something else']), ['Idempotency-Key' => 'retry-123'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'idempotency_key_reused');
    });

    it('accepts a personal token with incidents:create', function () {
        Sanctum::actingAs(userWithRole(RoleName::Noc), ['incidents:create']);

        $this->postJson('/api/v1/incidents', incidentPayload())->assertCreated();
    });

    it('returns 403 for a personal token without incidents:create', function () {
        Sanctum::actingAs(userWithRole(RoleName::Noc), ['incidents:read']);

        $this->postJson('/api/v1/incidents', incidentPayload())->assertForbidden();
    });
});

describe('index', function () {
    it('shows a reporter only the incidents they reported', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $own = Incident::factory()->create(['reporter_id' => $reporter->id]);
        Incident::factory()->create();

        $this->getJson('/api/v1/incidents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    });

    it('shows a responder incidents on their team\'s services and those assigned to them', function () {
        $responder = actingAsRole(RoleName::Responder);
        $team = Team::factory()->create();
        $team->addMember($responder);
        $onTeamService = Incident::factory()->forTeam($team)->create();
        $assigned = Incident::factory()->assignedTo($responder)->create();
        Incident::factory()->forTeam(Team::factory()->create())->create();

        $ids = collect($this->getJson('/api/v1/incidents')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        expect($ids)->toBe([$onTeamService->id, $assigned->id]);
    });

    it('shows NOC every incident', function () {
        actingAsRole(RoleName::Noc);
        Incident::factory()->count(3)->create();

        $this->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(3, 'data');
    });

    it('filters by comma-separated status and severity', function () {
        actingAsRole(RoleName::Noc);
        $match = Incident::factory()->severity(Severity::Sev1)->inStatus(IncidentStatus::Investigating)->create();
        Incident::factory()->severity(Severity::Sev1)->inStatus(IncidentStatus::Resolved)->create();
        Incident::factory()->severity(Severity::Sev4)->inStatus(IncidentStatus::Investigating)->create();

        $this->getJson('/api/v1/incidents?status=investigating,mitigated&severity=1,2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    });

    it('searches by title or exact reference', function () {
        actingAsRole(RoleName::Noc);
        $match = Incident::factory()->create(['title' => 'Database replication lag']);
        Incident::factory()->create(['title' => 'Login page slow']);

        $this->getJson('/api/v1/incidents?q=replication')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
        $this->getJson('/api/v1/incidents?q='.$match->fresh()->ref)->assertOk()->assertJsonCount(1, 'data');
    });

    it('sorts by severity then age', function () {
        actingAsRole(RoleName::Noc);
        $low = Incident::factory()->severity(Severity::Sev4)->create();
        $critical = Incident::factory()->severity(Severity::Sev1)->create();

        $this->getJson('/api/v1/incidents?sort=severity,reported_at')
            ->assertOk()
            ->assertJsonPath('data.0.id', $critical->id)
            ->assertJsonPath('data.1.id', $low->id);
    });

    it('rejects an unknown sort field', function () {
        actingAsRole(RoleName::Noc);

        $this->getJson('/api/v1/incidents?sort=password')->assertUnprocessable()->assertJsonValidationErrors('sort');
    });
});

describe('show', function () {
    it('returns the incident with the requested includes', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->getJson("/api/v1/incidents/{$incident->id}?include=history,updates,unknown")
            ->assertOk()
            ->assertJsonPath('data.id', $incident->id)
            ->assertJsonPath('data.history.0.to_status', 'reported')
            ->assertJsonPath('data.updates', [])
            ->assertJsonMissingPath('data.time_entries');
    });

    it('returns 404 for an incident the user cannot see', function () {
        actingAsRole(RoleName::Reporter);
        $someoneElses = Incident::factory()->create();

        $this->getJson("/api/v1/incidents/{$someoneElses->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('update', function () {
    it('lets the reporter edit their own incident', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create(['reporter_id' => $reporter->id]);

        $this->putJson("/api/v1/incidents/{$incident->id}", ['title' => 'Deposits failing for card payments only'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Deposits failing for card payments only');
    });
});

describe('destroy', function () {
    it('soft deletes with a reason for an incident manager', function () {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->create();

        $this->deleteJson("/api/v1/incidents/{$incident->id}", ['reason' => 'Test incident'])->assertNoContent();

        $this->assertSoftDeleted($incident);
        expect(Incident::withTrashed()->find($incident->id)->deletion_reason)->toBe('Test incident');
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'incident.deleted', 'entity_id' => $incident->id]);
    });

    it('returns 403 for an admin', function () {
        actingAsRole(RoleName::Admin);
        $incident = Incident::factory()->create();

        $this->deleteJson("/api/v1/incidents/{$incident->id}", ['reason' => 'Cleanup'])->assertForbidden();

        $this->assertNotSoftDeleted($incident);
    });

    it('requires a reason', function () {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->create();

        $this->deleteJson("/api/v1/incidents/{$incident->id}")->assertUnprocessable()->assertJsonValidationErrors('reason');
    });

    it('hides deleted incidents', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $incident->delete();

        $this->getJson("/api/v1/incidents/{$incident->id}")->assertNotFound();
    });
});

it('returns 401 without a token', function () {
    $incident = Incident::factory()->create();

    $this->getJson("/api/v1/incidents/{$incident->id}")->assertUnauthorized();
});

it('does not let a user without any incident.view permission list incidents', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/incidents')->assertForbidden();
});

describe('assigning while reporting', function () {
    it('gives the incident to the chosen person and tells them', function () {
        Notification::fake();
        $noc = actingAsRole(RoleName::Noc);
        $assignee = userWithRole(RoleName::Responder);
        RoutingRule::factory()->create(['team_id' => null, 'priority' => 99]);

        $this->postJson('/api/v1/incidents', incidentPayload(['assignee_id' => $assignee->id]))
            ->assertCreated()
            ->assertJsonPath('data.routing.assignee.id', $assignee->id)
            ->assertJsonPath('data.routing.assignee.name', $assignee->name);

        $incident = Incident::sole();

        // Routing's own assignment, then the manual one with a reason.
        expect($incident->assignments()->count())->toBe(2)
            ->and($incident->assignments()->latest('id')->first()->reason)->toBe('Chosen when reporting the incident')
            ->and($incident->assignee_id)->toBe($assignee->id);

        Notification::assertSentTo($assignee, InAppAlert::class);
        Notification::assertNotSentTo($noc, InAppAlert::class);
    });

    it('lets the reporter assign it to themselves when they hold incident.assign', function () {
        $manager = actingAsRole(RoleName::IncidentManager);
        RoutingRule::factory()->create(['team_id' => null, 'priority' => 99]);

        $this->postJson('/api/v1/incidents', incidentPayload(['assignee_id' => $manager->id]))
            ->assertCreated()
            ->assertJsonPath('data.routing.assignee.id', $manager->id);
    });

    it('refuses an assignee from someone who cannot assign incidents', function (RoleName $role) {
        actingAsRole($role);

        $this->postJson('/api/v1/incidents', incidentPayload(['assignee_id' => User::factory()->create()->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'assignee_id' => 'You cannot assign incidents. Leave the assignee empty and routing will choose.',
            ]);
    })->with([RoleName::Reporter, RoleName::Responder]);

    it('rejects a deactivated assignee', function () {
        actingAsRole(RoleName::Noc);

        $this->postJson('/api/v1/incidents', incidentPayload(['assignee_id' => User::factory()->create(['is_active' => false])->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee_id' => 'Choose an active user to assign it to.']);
    });
});
