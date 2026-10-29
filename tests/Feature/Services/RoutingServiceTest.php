<?php

use App\Enums\AssignmentMode;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentAssignment;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\RoutingRule;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\RoutingService;

function routing(): RoutingService
{
    return app(RoutingService::class);
}

/**
 * @return array{0: Team, 1: list<User>}
 */
function teamWithMembers(int $count = 2): array
{
    $team = Team::factory()->create();
    $members = User::factory()->count($count)->create()->each(fn (User $user) => $team->addMember($user))->all();

    return [$team, $members];
}

describe('rule matching', function () {
    it('takes the matching rule with the lowest priority number', function () {
        $database = Category::factory()->create();
        $dba = Team::factory()->create();
        RoutingRule::factory()->create(['priority' => 99, 'team_id' => Team::factory()]);
        $expected = RoutingRule::factory()->create(['priority' => 20, 'category_id' => $database->id, 'team_id' => $dba->id]);
        RoutingRule::factory()->create(['priority' => 30, 'category_id' => $database->id]);

        $decision = routing()->decide(null, $database->id, Severity::Sev3);

        expect($decision->rule->is($expected))->toBeTrue()
            ->and($decision->team->is($dba))->toBeTrue();
    });

    it('treats empty match fields as wildcards and checks the severity range', function () {
        $service = Service::factory()->create();
        $highOnly = RoutingRule::factory()->create(['priority' => 10, 'service_id' => $service->id, 'min_severity' => 1, 'max_severity' => 2]);
        $catchAll = RoutingRule::factory()->create(['priority' => 99]);

        expect(routing()->decide($service, null, Severity::Sev1)->rule->is($highOnly))->toBeTrue()
            ->and(routing()->decide($service, null, Severity::Sev3)->rule->is($catchAll))->toBeTrue()
            ->and(routing()->decide(Service::factory()->create(), null, Severity::Sev1)->rule->is($catchAll))->toBeTrue();
    });

    it('skips inactive rules', function () {
        RoutingRule::factory()->create(['priority' => 1, 'is_active' => false]);
        $active = RoutingRule::factory()->create(['priority' => 50]);

        expect(routing()->decide(null, null, Severity::Sev2)->rule->is($active))->toBeTrue();
    });

    it('routes a rule without a team to the service owner team', function () {
        $owner = Team::factory()->create();
        RoutingRule::factory()->create(['team_id' => null]);

        $decision = routing()->decide(Service::factory()->for($owner, 'ownerTeam')->create(), null, Severity::Sev3);

        expect($decision->team->is($owner))->toBeTrue();
    });

    it('routes to the default NOC team when no rule matches', function () {
        $noc = Team::factory()->create(['name' => 'NOC']);

        $decision = routing()->decide(Service::factory()->create(), null, Severity::Sev2);

        expect($decision->rule)->toBeNull()
            ->and($decision->team->is($noc))->toBeTrue();
    });
});

describe('assignment modes', function () {
    it('assigns the person on call', function () {
        [$team, [$alice, $bob]] = teamWithMembers();
        $schedule = OnCallSchedule::factory()->for($team)->create();
        OnCallShift::factory()->for($schedule, 'schedule')->create(['user_id' => $bob->id]);
        RoutingRule::factory()->create(['team_id' => $team->id, 'assignment_mode' => AssignmentMode::OnCall]);

        $decision = routing()->decide(null, null, Severity::Sev2);

        expect($decision->assignee->is($bob))->toBeTrue()
            ->and($decision->onCall->is($bob))->toBeTrue();
    });

    it('falls back to round robin when nobody is on call', function () {
        [$team, [$alice]] = teamWithMembers();
        RoutingRule::factory()->create(['team_id' => $team->id, 'assignment_mode' => AssignmentMode::OnCall]);

        expect(routing()->decide(null, null, Severity::Sev2)->assignee->is($alice))->toBeTrue();
    });

    it('rotates round robin to the member assigned least recently', function () {
        [$team, [$alice, $bob]] = teamWithMembers();
        RoutingRule::factory()->create(['team_id' => $team->id, 'assignment_mode' => AssignmentMode::RoundRobin]);
        IncidentAssignment::create(['incident_id' => Incident::factory()->create()->id, 'to_user_id' => $alice->id, 'team_id' => $team->id]);

        expect(routing()->decide(null, null, Severity::Sev3)->assignee->is($bob))->toBeTrue();

        IncidentAssignment::create(['incident_id' => Incident::factory()->create()->id, 'to_user_id' => $bob->id, 'team_id' => $team->id]);

        expect(routing()->decide(null, null, Severity::Sev3)->assignee->is($alice))->toBeTrue();
    });

    it('picks the member with the fewest open incidents for least_loaded', function () {
        [$team, [$alice, $bob]] = teamWithMembers();
        RoutingRule::factory()->create(['team_id' => $team->id, 'assignment_mode' => AssignmentMode::LeastLoaded]);
        Incident::factory()->count(2)->assignedTo($alice)->create();
        Incident::factory()->assignedTo($bob)->create();
        Incident::factory()->count(3)->assignedTo($bob)->inStatus(IncidentStatus::Resolved)->create();

        expect(routing()->decide(null, null, Severity::Sev3)->assignee->is($bob))->toBeTrue();
    });

    it('assigns nobody for mode none', function () {
        [$team] = teamWithMembers();
        RoutingRule::factory()->create(['team_id' => $team->id, 'assignment_mode' => AssignmentMode::None]);

        $decision = routing()->decide(null, null, Severity::Sev3);

        expect($decision->assignee)->toBeNull()
            ->and($decision->team->is($team))->toBeTrue();
    });

    it('skips deactivated members and falls back to the team lead', function () {
        $team = Team::factory()->create();
        $lead = User::factory()->create();
        $team->addMember($lead, isLead: true);
        $inactive = User::factory()->inactive()->create();
        $team->addMember($inactive);
        RoutingRule::factory()->create(['team_id' => $team->id, 'assignment_mode' => AssignmentMode::RoundRobin]);

        expect(routing()->decide(null, null, Severity::Sev3)->assignee->is($lead))->toBeTrue();
    });
});
