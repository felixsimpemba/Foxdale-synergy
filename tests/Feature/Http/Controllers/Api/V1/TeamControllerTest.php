<?php

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('index and show', function () {
    it('lists teams with their lead and member count for any signed-in user', function () {
        actingAsRole(RoleName::Reporter);
        $team = Team::factory()->create(['name' => 'Payments']);
        $lead = User::factory()->create();
        $team->addMember($lead, isLead: true);
        $team->addMember(User::factory()->create());

        $this->getJson('/api/v1/teams')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Payments')
            ->assertJsonPath('data.0.lead.id', $lead->id)
            ->assertJsonPath('data.0.members_count', 2);
    });

    it('includes active members on request, for people pickers', function () {
        actingAsRole(RoleName::Responder);
        $team = Team::factory()->create();
        $active = User::factory()->create(['name' => 'Ada']);
        $team->addMember($active);
        $team->addMember(User::factory()->create(['is_active' => false]));

        $this->getJson('/api/v1/teams')->assertOk()->assertJsonMissingPath('data.0.members');
        $this->getJson('/api/v1/teams?include=members')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.members')
            ->assertJsonPath('data.0.members.0.id', $active->id);
    });

    it('is readable with a scoped personal token', function () {
        Sanctum::actingAs(User::factory()->create(), ['mcp:read']);

        $this->getJson('/api/v1/teams')->assertOk();
    });

    it('shows a team with its members', function () {
        actingAsRole(RoleName::Reporter);
        $team = Team::factory()->create();
        $member = User::factory()->create();
        $team->addMember($member);

        $this->getJson("/api/v1/teams/{$team->id}")
            ->assertOk()
            ->assertJsonPath('data.members.0.id', $member->id)
            ->assertJsonPath('data.members.0.is_lead', false);
    });

    it('returns 404 in the standard error format for a missing team', function () {
        actingAsRole(RoleName::Reporter);

        $this->getJson('/api/v1/teams/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.', 'code' => 'not_found']);
    });
});

describe('store, update and destroy', function () {
    it('creates a team', function () {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/teams', ['name' => 'Security', 'description' => 'SecOps'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Security');

        $this->assertDatabaseHas(Team::class, ['name' => 'Security']);
    });

    it('rejects a duplicate name', function () {
        actingAsRole(RoleName::Admin);
        Team::factory()->create(['name' => 'Security']);

        $this->postJson('/api/v1/teams', ['name' => 'Security'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('returns 403 for a reporter', function () {
        actingAsRole(RoleName::Reporter);

        $this->postJson('/api/v1/teams', ['name' => 'Security'])->assertForbidden();
    });

    it('returns 403 for an admin using a personal token', function () {
        Sanctum::actingAs(userWithRole(RoleName::Admin), ['mcp:write']);

        $this->postJson('/api/v1/teams', ['name' => 'Security'])->assertForbidden();
    });

    it('renames a team', function () {
        actingAsRole(RoleName::Admin);
        $team = Team::factory()->create();

        $this->putJson("/api/v1/teams/{$team->id}", ['name' => 'DBA'])->assertOk()->assertJsonPath('data.name', 'DBA');
    });

    it('deletes a team', function () {
        actingAsRole(RoleName::Admin);
        $team = Team::factory()->create();

        $this->deleteJson("/api/v1/teams/{$team->id}")->assertNoContent();

        $this->assertModelMissing($team);
    });
});

describe('members', function () {
    it('makes a member the lead and demotes the previous lead', function () {
        actingAsRole(RoleName::Admin);
        $team = Team::factory()->create();
        $oldLead = User::factory()->create();
        $newLead = User::factory()->create();
        $team->addMember($oldLead, isLead: true);

        $this->postJson("/api/v1/teams/{$team->id}/members", ['user_id' => $newLead->id, 'is_lead' => true])
            ->assertOk()
            ->assertJsonPath('data.lead.id', $newLead->id);

        expect($team->members()->wherePivot('is_lead', true)->pluck('users.id')->all())->toBe([$newLead->id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'team.member_added', 'entity_id' => $team->id]);
    });

    it('clears the lead when the lead is removed', function () {
        actingAsRole(RoleName::Admin);
        $team = Team::factory()->create();
        $lead = User::factory()->create();
        $team->addMember($lead, isLead: true);

        $this->deleteJson("/api/v1/teams/{$team->id}/members/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.lead', null);

        expect($team->fresh()->lead_user_id)->toBeNull()
            ->and($team->members()->count())->toBe(0);
    });

    it('returns 404 when removing someone who is not a member', function () {
        actingAsRole(RoleName::Admin);
        $team = Team::factory()->create();
        $stranger = User::factory()->create();

        $this->deleteJson("/api/v1/teams/{$team->id}/members/{$stranger->id}")->assertNotFound();
    });
});
