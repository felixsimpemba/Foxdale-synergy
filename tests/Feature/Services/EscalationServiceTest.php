<?php

use App\Actions\Incidents\CreateIncident;
use App\Actions\Incidents\TransitionIncident;
use App\Enums\EscalationTargetType;
use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Events\IncidentEscalated;
use App\Jobs\CheckSlaTimers;
use App\Models\Category;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\RoutingRule;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

/**
 * Payments team with an on-call engineer and a lead, routed by a rule using the example
 * escalation policy: on-call at 0, team lead at 10, incident managers at 20 minutes, and a
 * SEV1-only level 4 to a named user at 30 minutes.
 *
 * @return array{team: Team, onCall: User, lead: User, manager: User, head: User}
 */
function escalationSetup(): array
{
    $team = Team::factory()->create();
    $onCall = User::factory()->create();
    $lead = User::factory()->create();
    $team->addMember($onCall);
    $team->addMember($lead, isLead: true);
    OnCallShift::factory()->for(OnCallSchedule::factory()->for($team)->create(), 'schedule')
        ->create(['user_id' => $onCall->id, 'ends_at' => now()->addDay()]);

    $manager = userWithRole(RoleName::IncidentManager);
    $head = User::factory()->create(['name' => 'Head of Engineering']);

    $policy = EscalationPolicy::factory()->withStandardLevels(Role::findByName('incident_manager', 'web')->id)->create();
    $policy->levels()->create([
        'level' => 4, 'after_minutes' => 30, 'target_type' => EscalationTargetType::User, 'target_id' => $head->id, 'max_severity' => 1,
    ]);
    RoutingRule::factory()->create(['team_id' => $team->id, 'escalation_policy_id' => $policy->id]);

    return compact('team', 'onCall', 'lead', 'manager', 'head');
}

function reportIncident(int $severity): Incident
{
    return app(CreateIncident::class)->handle(User::factory()->create(), [
        'title' => 'Deposits failing',
        'description' => 'Callbacks time out',
        'severity' => $severity,
        'service_id' => Service::factory()->create()->id,
        'category_id' => Category::factory()->create()->id,
    ]);
}

it('assigns the on-call engineer and schedules level 2 at once', function () {
    $this->freezeTime();
    ['onCall' => $onCall] = escalationSetup();

    $incident = reportIncident(2);

    expect($incident)
        ->assignee_id->toBe($onCall->id)
        ->escalation_level->toBe(1)
        ->and($incident->next_escalation_at->toIso8601ZuluString())->toBe(now()->addMinutes(10)->toIso8601ZuluString());
});

it('escalates an unacknowledged SEV1 through every level on time', function () {
    $this->freezeTime();
    ['lead' => $lead, 'manager' => $manager, 'head' => $head] = escalationSetup();
    $incident = reportIncident(1);
    Event::fake([IncidentEscalated::class]);

    $this->travel(9)->minutes();
    dispatch_sync(new CheckSlaTimers);
    Event::assertNotDispatched(IncidentEscalated::class);

    $this->travel(1)->minutes();
    dispatch_sync(new CheckSlaTimers);
    $this->travel(10)->minutes();
    dispatch_sync(new CheckSlaTimers);
    $this->travel(10)->minutes();
    dispatch_sync(new CheckSlaTimers);
    $this->travel(30)->minutes();
    dispatch_sync(new CheckSlaTimers);

    $recipientsPerLevel = [];
    Event::assertDispatched(IncidentEscalated::class, function (IncidentEscalated $event) use (&$recipientsPerLevel) {
        $recipientsPerLevel[$event->level->level] = $event->recipients->modelKeys();

        return true;
    });

    expect($recipientsPerLevel)->toBe([2 => [$lead->id], 3 => [$manager->id], 4 => [$head->id]])
        ->and($incident->fresh())
        ->escalation_level->toBe(4)
        ->next_escalation_at->toBeNull();
});

it('skips the SEV1-only level for a SEV2 incident', function () {
    $this->freezeTime();
    escalationSetup();
    $incident = reportIncident(2);

    $this->travel(10)->minutes();
    dispatch_sync(new CheckSlaTimers);
    $this->travel(10)->minutes();
    dispatch_sync(new CheckSlaTimers);

    expect($incident->fresh())
        ->escalation_level->toBe(3)
        ->next_escalation_at->toBeNull();
});

it('stops escalating once the incident is acknowledged', function () {
    $this->freezeTime();
    ['onCall' => $onCall] = escalationSetup();
    $incident = reportIncident(1);

    $this->travel(5)->minutes();
    app(TransitionIncident::class)->handle($incident, $onCall, IncidentStatus::Acknowledged);
    Event::fake([IncidentEscalated::class]);

    $this->travel(1)->hours();
    dispatch_sync(new CheckSlaTimers);

    Event::assertNotDispatched(IncidentEscalated::class);
    expect($incident->fresh()->next_escalation_at)->toBeNull();
});

it('notes each escalation on the timeline', function () {
    $this->freezeTime();
    ['lead' => $lead] = escalationSetup();
    $incident = reportIncident(2);

    $this->travel(10)->minutes();
    dispatch_sync(new CheckSlaTimers);

    expect($incident->updates()->sole()->body)->toBe("Escalated to level 2 (team_lead): {$lead->name}");
});
