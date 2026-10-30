<?php

use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;

/*
| Visibility rules (specification section 4): incident.view.own covers incidents reported by
| or assigned to the user; incident.view.team adds anything routed to, or on a service owned
| by, their teams; NOC, incident managers and admins see everything.
*/

dataset('relationships', [
    'reported by the user' => ['reporter'],
    'assigned to the user' => ['assignee'],
    'routed to the user\'s team' => ['routed_team'],
    'on a service owned by the user\'s team' => ['service_team'],
    'unrelated' => ['unrelated'],
]);

function incidentRelatedTo(User $user, string $relationship): Incident
{
    $team = Team::factory()->create();
    $team->addMember($user);

    return match ($relationship) {
        'reporter' => Incident::factory()->create(['reporter_id' => $user->id]),
        'assignee' => Incident::factory()->assignedTo($user)->create(),
        'routed_team' => Incident::factory()->create(['team_id' => $team->id]),
        'service_team' => Incident::factory()->create(['service_id' => Service::factory()->for($team, 'ownerTeam')]),
        'unrelated' => Incident::factory()->create(),
    };
}

test('reporters see incidents they reported or were assigned, not team incidents', function (string $relationship) {
    $user = userWithRole(RoleName::Reporter);
    $incident = incidentRelatedTo($user, $relationship);

    expect($user->can('view', $incident))->toBe(in_array($relationship, ['reporter', 'assignee'], true));
})->with('relationships');

test('responders see their own, assigned and team incidents', function (string $relationship) {
    $user = userWithRole(RoleName::Responder);
    $incident = incidentRelatedTo($user, $relationship);

    expect($user->can('view', $incident))->toBe($relationship !== 'unrelated');
})->with('relationships');

test('NOC, incident managers and admins see every incident', function (RoleName $role) {
    $user = userWithRole($role);

    expect($user->can('view', incidentRelatedTo($user, 'unrelated')))->toBeTrue();
})->with([RoleName::Noc, RoleName::IncidentManager, RoleName::Admin]);

test('the list query and the policy agree', function () {
    $user = userWithRole(RoleName::Responder);
    $visible = collect(['reporter', 'assignee', 'routed_team', 'service_team'])
        ->map(fn (string $relationship) => incidentRelatedTo($user, $relationship)->id);
    incidentRelatedTo($user, 'unrelated');

    expect(Incident::query()->visibleTo($user)->pluck('id')->sort()->values()->all())->toBe($visible->sort()->values()->all());
});

test('only incident managers can delete incidents', function (RoleName $role, bool $allowed) {
    expect(userWithRole($role)->can('delete', Incident::factory()->create()))->toBe($allowed);
})->with([
    [RoleName::Noc, false],
    [RoleName::IncidentManager, true],
    [RoleName::Admin, false],
]);
