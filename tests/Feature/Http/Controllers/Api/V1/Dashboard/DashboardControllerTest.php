<?php

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Enums\Severity;
use App\Enums\SlaEventType;
use App\Enums\SlaMetric;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSlaEvent;
use App\Models\Service;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
});

/**
 * A resolved incident reported $daysAgo days before now, acknowledged and resolved the given
 * number of minutes after the report, with a resolve target of $targetMinutes.
 *
 * @param  array<string, mixed>  $attributes
 */
function resolvedIncident(int $daysAgo, int $ackMinutes, int $resolveMinutes, int $targetMinutes = 240, array $attributes = []): Incident
{
    $reportedAt = now()->subDays($daysAgo)->setTime(9, 0);

    return Incident::factory()->create([
        'status' => IncidentStatus::Resolved,
        'reported_at' => $reportedAt,
        'acknowledged_at' => $reportedAt->copy()->addMinutes($ackMinutes),
        'resolved_at' => $reportedAt->copy()->addMinutes($resolveMinutes),
        'ack_due_at' => $reportedAt->copy()->addMinutes(15),
        'resolve_due_at' => $reportedAt->copy()->addMinutes($targetMinutes),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function openIncident(Severity $severity, int $minutesAgo, array $attributes = []): Incident
{
    $reportedAt = now()->subMinutes($minutesAgo);

    return Incident::factory()->severity($severity)->create([
        'reported_at' => $reportedAt,
        'ack_due_at' => $reportedAt->copy()->addMinutes(15),
        'mitigate_due_at' => $reportedAt->copy()->addMinutes(60),
        'resolve_due_at' => $reportedAt->copy()->addMinutes(240),
        ...$attributes,
    ]);
}

describe('summary', function () {
    it('returns the KPI cards for the period', function () {
        actingAsRole(RoleName::IncidentManager);

        // Resolved in the period: 60, 120 and 600 minutes; the last missed its 240-minute target.
        resolvedIncident(2, 4, 60);
        resolvedIncident(3, 6, 120);
        $late = resolvedIncident(4, 20, 600);
        IncidentSlaEvent::create(['incident_id' => $late->id, 'metric' => SlaMetric::Resolve, 'type' => SlaEventType::Breached, 'due_at' => $late->resolve_due_at, 'occurred_at' => $late->resolve_due_at]);
        $late->statusHistory()->create(['from_status' => IncidentStatus::Resolved, 'to_status' => IncidentStatus::Investigating, 'changed_at' => now()->subDay()]);

        openIncident(Severity::Sev2, 30, ['status' => IncidentStatus::Investigating, 'acknowledged_at' => now()->subMinutes(25)]);
        openIncident(Severity::Sev3, 20);
        openIncident(Severity::Sev3, 5, ['source' => IncidentSource::Monitoring]);

        // Outside the period: counted only in "previous".
        resolvedIncident(40, 10, 100);

        $this->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.period', ['from' => '2026-09-29', 'to' => '2026-10-28'])
            ->assertJsonPath('data.open', ['total' => 3, 'sev1' => 0, 'sev2' => 1, 'sev3' => 2, 'sev4' => 0])
            ->assertJsonPath('data.unacknowledged', 1)
            ->assertJsonPath('data.reported', 6)
            ->assertJsonPath('data.resolved', 3)
            ->assertJsonPath('data.mtta_minutes', 8.8)
            ->assertJsonPath('data.mttr_minutes', ['avg' => 260, 'median' => 120, 'p90' => 600])
            ->assertJsonPath('data.sla_compliance_pct', 66.7)
            ->assertJsonPath('data.sla_breached', 1)
            ->assertJsonPath('data.reopen_rate_pct', 33.3)
            ->assertJsonPath('data.detection', ['manual' => 5, 'monitoring' => 1, 'mcp' => 0])
            ->assertJsonPath('data.previous.resolved', 1)
            ->assertJsonPath('data.previous.mttr_minutes', 100);
    });

    it('returns null averages when nothing happened in the period', function () {
        actingAsRole(RoleName::Noc);

        $this->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.resolved', 0)
            ->assertJsonPath('data.mtta_minutes', null)
            ->assertJsonPath('data.mttr_minutes', ['avg' => null, 'median' => null, 'p90' => null])
            ->assertJsonPath('data.sla_compliance_pct', null)
            ->assertJsonPath('data.reopen_rate_pct', null);
    });

    it('counts incidents on the same service and category as one in the previous 30 days as recurring', function () {
        actingAsRole(RoleName::Noc);
        $service = Service::factory()->create();
        $category = Category::factory()->create();

        resolvedIncident(20, 5, 60, attributes: ['service_id' => $service->id, 'category_id' => $category->id]);
        resolvedIncident(5, 5, 60, attributes: ['service_id' => $service->id, 'category_id' => $category->id]);
        resolvedIncident(4, 5, 60, attributes: ['service_id' => $service->id, 'category_id' => Category::factory()->create()->id]);

        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.recurring', 1);
    });

    it('only counts incidents the user can see', function () {
        $responder = actingAsRole(RoleName::Responder);
        $team = Team::factory()->create();
        $team->members()->attach($responder);

        Incident::factory()->forTeam($team)->create();
        Incident::factory()->count(2)->create();

        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.open.total', 1);
    });

    it('narrows to a team and a service', function () {
        actingAsRole(RoleName::Noc);
        $team = Team::factory()->create();
        $service = Service::factory()->create();

        Incident::factory()->create(['team_id' => $team->id]);
        Incident::factory()->create(['service_id' => $service->id]);
        Incident::factory()->create();

        $this->getJson("/api/v1/dashboard/summary?team_id={$team->id}")->assertJsonPath('data.open.total', 1);
        $this->getJson("/api/v1/dashboard/summary?service_id={$service->id}")->assertJsonPath('data.open.total', 1);
    });

    it('returns 403 for reporters, who cannot view dashboards', function () {
        actingAsRole(RoleName::Reporter);

        $this->getJson('/api/v1/dashboard/summary')->assertForbidden();
    });

    it('rejects a period longer than a year', function () {
        actingAsRole(RoleName::Noc);

        $this->getJson('/api/v1/dashboard/summary?from=2025-01-01&to=2026-10-28')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    });

    it('recomputes cached figures after an incident changes', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->getJson('/api/v1/dashboard/summary')->assertJsonPath('data.open.total', 1);

        $incident->update(['status' => IncidentStatus::Cancelled]);

        $this->getJson('/api/v1/dashboard/summary')->assertJsonPath('data.open.total', 0);
    });
});

describe('trends', function () {
    it('returns a zero-filled daily series in the user timezone', function () {
        $user = actingAsRole(RoleName::Noc);
        $user->update(['timezone' => 'Africa/Lusaka']);

        // 23:30 UTC on the 26th is already the 27th in Lusaka (UTC+2).
        Incident::factory()->create(['reported_at' => Carbon::parse('2026-10-26 23:30', 'UTC')]);
        Incident::factory()->count(2)->create(['reported_at' => Carbon::parse('2026-10-28 08:00', 'UTC')]);

        $points = $this->getJson('/api/v1/dashboard/trends?metric=opened&from=2026-10-26&to=2026-10-28')
            ->assertOk()
            ->assertJsonPath('data.metric', 'opened')
            ->assertJsonPath('data.interval', 'day')
            ->json('data.points');

        expect($points)->toBe([
            ['period' => '2026-10-26', 'value' => 0, 'count' => 0],
            ['period' => '2026-10-27', 'value' => 1, 'count' => 1],
            ['period' => '2026-10-28', 'value' => 2, 'count' => 2],
        ]);
    });

    it('averages resolve minutes per week and leaves empty weeks null', function () {
        actingAsRole(RoleName::Noc);

        // Wednesday 2026-10-28 and Monday 2026-10-26 fall in the same ISO week.
        resolvedIncident(0, 5, 60);
        resolvedIncident(2, 5, 120);

        $points = $this->getJson('/api/v1/dashboard/trends?metric=mttr&interval=week&from=2026-10-12&to=2026-10-28')
            ->assertOk()
            ->json('data.points');

        expect($points)->toEqual([
            ['period' => '2026-10-12', 'value' => null, 'count' => 0],
            ['period' => '2026-10-19', 'value' => null, 'count' => 0],
            ['period' => '2026-10-26', 'value' => 90, 'count' => 2],
        ]);
    });

    it('requires a known metric', function () {
        actingAsRole(RoleName::Noc);

        $this->getJson('/api/v1/dashboard/trends')->assertUnprocessable()->assertJsonValidationErrors('metric');
        $this->getJson('/api/v1/dashboard/trends?metric=uptime')->assertUnprocessable()->assertJsonValidationErrors('metric');
    });
});

describe('breakdown', function () {
    it('groups incidents in the period by service, largest first, with a None group', function () {
        actingAsRole(RoleName::Noc);
        $gateway = Service::factory()->create(['name' => 'Payment gateway']);
        $portal = Service::factory()->create(['name' => 'Customer portal']);

        resolvedIncident(1, 5, 30, attributes: ['service_id' => $gateway->id]);
        openIncident(Severity::Sev2, 10, ['service_id' => $gateway->id, 'sla_breached' => true]);
        openIncident(Severity::Sev3, 10, ['service_id' => $portal->id]);
        openIncident(Severity::Sev3, 10);

        $this->getJson('/api/v1/dashboard/breakdown?by=service')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0', ['key' => $gateway->id, 'label' => 'Payment gateway', 'total' => 2, 'open' => 1, 'resolved' => 1, 'breached' => 1, 'mttr_minutes' => 30]);

        expect(collect($this->getJson('/api/v1/dashboard/breakdown?by=service')->json('data'))->pluck('label')->sort()->values()->all())
            ->toBe(['Customer portal', 'None', 'Payment gateway']);
    });

    it('labels severities', function () {
        actingAsRole(RoleName::Noc);
        openIncident(Severity::Sev1, 5);

        $this->getJson('/api/v1/dashboard/breakdown?by=severity')
            ->assertOk()
            ->assertJsonPath('data.0.key', 1)
            ->assertJsonPath('data.0.label', 'SEV1');
    });
});

describe('sla', function () {
    it('reports compliance by severity, recent breaches and incidents at risk', function () {
        actingAsRole(RoleName::IncidentManager);

        resolvedIncident(1, 5, 60, attributes: ['severity' => Severity::Sev2]);
        $late = resolvedIncident(2, 30, 600, attributes: ['severity' => Severity::Sev2]);
        IncidentSlaEvent::create(['incident_id' => $late->id, 'metric' => SlaMetric::Resolve, 'type' => SlaEventType::Breached, 'due_at' => $late->resolve_due_at, 'occurred_at' => $late->resolve_due_at]);

        // 13 of 15 minutes to acknowledge used: at risk. 2 minutes in: not yet.
        $soon = openIncident(Severity::Sev1, 13);
        openIncident(Severity::Sev3, 2);
        $overdue = openIncident(Severity::Sev2, 30);

        $response = $this->getJson('/api/v1/dashboard/sla')->assertOk();

        expect($response->json('data.by_severity.1'))->toEqual([
            'severity' => 2, 'resolved' => 2, 'resolve_met' => 1, 'resolve_pct' => 50,
            'acknowledged' => 2, 'ack_met' => 1, 'ack_pct' => 50,
        ])
            ->and($response->json('data.overall.resolve_pct'))->toEqual(50)
            ->and($response->json('data.breaches'))->toHaveCount(1)
            ->and($response->json('data.breaches.0.incident.id'))->toBe($late->id)
            ->and($response->json('data.breaches.0.metric'))->toBe('resolve')
            ->and(collect($response->json('data.at_risk'))->pluck('incident.id')->all())->toBe([$overdue->id, $soon->id])
            ->and($response->json('data.at_risk.0.breached'))->toBeTrue()
            ->and($response->json('data.at_risk.1.metric'))->toBe('ack')
            ->and($response->json('data.at_risk.1.minutes_left'))->toBe(2);
    });
});

describe('workload', function () {
    it('lists open incidents, resolutions and logged minutes per responder', function () {
        actingAsRole(RoleName::Noc);
        $ada = User::factory()->create(['name' => 'Ada']);
        $ben = User::factory()->create(['name' => 'Ben']);

        Incident::factory()->assignedTo($ada)->severity(Severity::Sev1)->create();
        Incident::factory()->assignedTo($ada)->create();
        $resolved = resolvedIncident(1, 5, 60, attributes: ['assignee_id' => $ben->id]);
        TimeEntry::factory()->create(['incident_id' => $resolved->id, 'user_id' => $ben->id, 'minutes' => 45, 'worked_on' => today()->subDay()]);
        TimeEntry::factory()->create(['incident_id' => $resolved->id, 'user_id' => $ben->id, 'minutes' => 30, 'worked_on' => today()->subDays(60)]);
        Incident::factory()->create();

        $this->getJson('/api/v1/dashboard/workload')
            ->assertOk()
            ->assertJsonPath('data.unassigned_open', 1)
            ->assertJsonPath('data.people', [
                ['user' => ['id' => $ada->id, 'name' => 'Ada'], 'open' => 2, 'open_high' => 1, 'resolved' => 0, 'minutes_logged' => 0],
                ['user' => ['id' => $ben->id, 'name' => 'Ben'], 'open' => 0, 'open_high' => 0, 'resolved' => 1, 'minutes_logged' => 45],
            ]);
    });
});

describe('current', function () {
    it('lists open incidents, most severe then oldest first', function () {
        actingAsRole(RoleName::Noc);
        $newSev3 = openIncident(Severity::Sev3, 5);
        $oldSev3 = openIncident(Severity::Sev3, 50);
        $sev1 = openIncident(Severity::Sev1, 1);
        resolvedIncident(1, 5, 60);

        $ids = collect($this->getJson('/api/v1/dashboard/current')->assertOk()->json('data'))->pluck('id')->all();

        expect($ids)->toBe([$sev1->id, $oldSev3->id, $newSev3->id]);
    });
});

describe('overview', function () {
    it('returns every dashboard section in one response, matching the separate endpoints', function () {
        actingAsRole(RoleName::Noc);
        resolvedIncident(2, 5, 60, attributes: ['service_id' => Service::factory()->create()->id]);
        openIncident(Severity::Sev2, 30);

        $overview = $this->getJson('/api/v1/dashboard/overview?interval=week')->assertOk()->json('data');

        expect(array_keys($overview))->toBe(['summary', 'trends', 'breakdowns', 'sla', 'workload', 'action_items'])
            ->and(array_keys($overview['trends']))->toBe(['opened', 'resolved', 'mtta', 'mttr'])
            ->and(array_keys($overview['breakdowns']))->toBe(['service', 'category', 'team', 'severity'])
            ->and($overview['trends']['opened']['interval'])->toBe('week')
            ->and($overview['summary'])->toEqual($this->getJson('/api/v1/dashboard/summary')->json('data'))
            ->and($overview['breakdowns']['service'])->toEqual($this->getJson('/api/v1/dashboard/breakdown?by=service')->json('data'))
            ->and($overview['action_items'])->toEqual($this->getJson('/api/v1/dashboard/action-items')->json('data'));
    });

    it('returns 403 for reporters', function () {
        actingAsRole(RoleName::Reporter);

        $this->getJson('/api/v1/dashboard/overview')->assertForbidden();
    });
});
