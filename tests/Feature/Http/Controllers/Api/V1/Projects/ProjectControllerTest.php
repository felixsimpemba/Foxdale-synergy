<?php

use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

describe('index and show', function () {
    it('lists only the projects the person can see', function () {
        $user = actingAsRole(RoleName::Reporter);

        Project::factory()->ledBy($user)->create(['name' => 'Led project']);
        Project::factory()->withMember($user)->create(['name' => 'Member project']);

        $team = Team::factory()->create();
        $team->addMember($user);
        Project::factory()->forTeam($team)->create(['name' => 'Team project']);
        Project::factory()->create(['name' => 'Hidden project']);

        $response = $this->getJson('/api/v1/projects')->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        expect($response->json('data'))->toHaveCount(3)
            ->and($names)->toContain('Led project', 'Member project', 'Team project')
            ->not->toContain('Hidden project');
    });

    it('shows every project to someone who can manage projects', function () {
        actingAsRole(RoleName::IncidentManager);
        Project::factory()->count(3)->create();

        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(3, 'data');
    });

    it('hides archived projects unless they are asked for', function () {
        $user = actingAsRole(RoleName::Reporter);
        Project::factory()->ledBy($user)->create([
            'name' => 'Old project',
            'status' => ProjectStatus::Archived,
            'archived_at' => now(),
        ]);

        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/projects?include_archived=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/projects?status=archived')->assertOk()->assertJsonCount(1, 'data');
    });

    it('filters by text and by projects the person belongs to', function () {
        $user = actingAsRole(RoleName::Reporter);
        Project::factory()->ledBy($user)->create(['name' => 'Synergy platform', 'key' => 'SYN']);
        Project::factory()->create(['name' => 'Other platform', 'key' => 'OPS']);

        $this->getJson('/api/v1/projects?q=SYN')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/projects?mine=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'SYN');
    });

    it('shows a project with its members, counts and active sprint', function () {
        $lead = actingAsRole(RoleName::Reporter);
        $project = Project::factory()->ledBy($lead)->create(['name' => 'Delivery']);
        $member = User::factory()->create();
        $project->members()->syncWithoutDetaching([
            $member->id => ['role' => ProjectMemberRole::Member->value],
        ]);

        Task::factory()->count(2)->forProject($project)->create();
        Task::factory()->forProject($project)->inStatus(TaskStatus::Done)->create();

        $response = $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Delivery')
            ->assertJsonPath('data.members_count', 2)
            ->assertJsonPath('data.tasks_count', 3)
            ->assertJsonPath('data.open_tasks_count', 2);

        $roles = collect($response->json('data.members'))->pluck('role', 'id');
        expect($roles[$lead->id])->toBe('manager')
            ->and($roles[$member->id])->toBe('member');
    });

    it('returns 404 for a project the person cannot see', function () {
        actingAsRole(RoleName::Reporter);
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
    });
});

describe('store, update and archive', function () {
    it('creates a project and makes the lead a manager', function () {
        $actor = actingAsRole(RoleName::IncidentManager);

        $response = $this->postJson('/api/v1/projects', [
            'key' => 'syn',
            'name' => 'Synergy platform',
            'description' => 'Planned work',
        ])->assertCreated()
            ->assertJsonPath('data.key', 'SYN')
            ->assertJsonPath('data.name', 'Synergy platform')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.lead_id', $actor->id);

        $project = Project::query()->findOrFail($response->json('data.id'));

        expect($project->members()->whereKey($actor->id)->first()->pivot->role)
            ->toBe(ProjectMemberRole::Manager)
            ->and($project->members()->count())->toBe(1);
    });

    it('rejects duplicate and badly formed keys', function () {
        actingAsRole(RoleName::IncidentManager);
        Project::factory()->create(['key' => 'SYN']);

        $this->postJson('/api/v1/projects', ['key' => 'SYN', 'name' => 'Other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');
        $this->postJson('/api/v1/projects', ['key' => '1x', 'name' => 'Other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');
    });

    it('does not let a reporter create a project', function () {
        actingAsRole(RoleName::Reporter);

        $this->postJson('/api/v1/projects', ['key' => 'OPS', 'name' => 'Ops'])->assertForbidden();
    });

    it('lets a project manager update without a global role', function () {
        $actor = actingAsRole(RoleName::Reporter);
        $project = Project::factory()->withMember($actor, ProjectMemberRole::Manager)->create();

        $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed', 'status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.status', 'paused');
    });

    it('does not let a plain member change the project', function () {
        $actor = actingAsRole(RoleName::Reporter);
        $project = Project::factory()->withMember($actor, ProjectMemberRole::Member)->create();

        $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])->assertForbidden();
    });

    it('archives on delete and hides it from the default list', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        expect($project->fresh()->status)->toBe(ProjectStatus::Archived)
            ->and($project->fresh()->archived_at)->not->toBeNull();
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('members', function () {
    it('adds, changes and removes a member with an audit trail', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $response = $this->postJson("/api/v1/projects/{$project->id}/members", [
            'user_id' => $user->id,
            'role' => 'manager',
        ])->assertOk();

        expect(collect($response->json('data.members'))->firstWhere('id', $user->id)['role'])
            ->toBe('manager');
        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'project.member_added',
            'entity_id' => $project->id,
        ]);

        $this->postJson("/api/v1/projects/{$project->id}/members", [
            'user_id' => $user->id,
            'role' => 'viewer',
        ])->assertOk();

        expect($project->fresh()->memberRole($user))->toBe(ProjectMemberRole::Viewer);

        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$user->id}")->assertOk();

        expect($project->fresh()->members()->whereKey($user->id)->exists())->toBeFalse();
        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'project.member_removed',
            'entity_id' => $project->id,
        ]);
    });

    it('refuses to remove the lead', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $lead = User::query()->findOrFail($project->lead_id);

        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$lead->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'lead_member');
    });

    it('returns 404 when removing someone who is not a member', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $stranger = User::factory()->create();

        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$stranger->id}")->assertNotFound();
    });

    it('does not let a plain member manage the member list', function () {
        $actor = actingAsRole(RoleName::Reporter);
        $project = Project::factory()->withMember($actor, ProjectMemberRole::Member)->create();
        $other = User::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/members", ['user_id' => $other->id])
            ->assertForbidden();
    });
});
