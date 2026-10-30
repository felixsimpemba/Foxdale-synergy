<?php

use App\Enums\ProjectMemberRole;
use App\Enums\RoleName;
use App\Enums\SprintState;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;

describe('index and store', function () {
    it('lists sprints with their task counts', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id, 'name' => 'Sprint 1']);

        Task::factory()->inSprint($sprint)->create();
        Task::factory()->inSprint($sprint)->inStatus(TaskStatus::Done)->create();

        $this->getJson("/api/v1/projects/{$project->id}/sprints")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sprint 1')
            ->assertJsonPath('data.0.tasks_count', 2)
            ->assertJsonPath('data.0.done_tasks_count', 1);
    });

    it('creates a sprint for a project manager', function () {
        $actor = actingAsRole(RoleName::Reporter);
        $project = Project::factory()->withMember($actor, ProjectMemberRole::Manager)->create();

        $this->postJson("/api/v1/projects/{$project->id}/sprints", [
            'name' => 'Sprint 2',
            'goal' => 'Ship the board',
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-13',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sprint 2')
            ->assertJsonPath('data.state', 'planned');
    });

    it('rejects duplicate names in the same project and bad dates', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        Sprint::factory()->create(['project_id' => $project->id, 'name' => 'Sprint 2']);

        $this->postJson("/api/v1/projects/{$project->id}/sprints", ['name' => 'Sprint 2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->postJson("/api/v1/projects/{$project->id}/sprints", [
            'name' => 'Sprint 3',
            'starts_on' => '2026-11-13',
            'ends_on' => '2026-11-02',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    });

    it('does not let a plain member create a sprint', function () {
        $actor = actingAsRole(RoleName::Reporter);
        $project = Project::factory()->withMember($actor, ProjectMemberRole::Member)->create();

        $this->postJson("/api/v1/projects/{$project->id}/sprints", ['name' => 'Sprint 2'])
            ->assertForbidden();
    });

    it('returns 404 to someone outside the project', function () {
        actingAsRole(RoleName::Reporter);
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}/sprints")->assertNotFound();
    });
});

describe('update and destroy', function () {
    it('updates a planned sprint', function () {
        actingAsRole(RoleName::IncidentManager);
        $sprint = Sprint::factory()->create(['name' => 'Sprint 1']);

        $this->putJson("/api/v1/sprints/{$sprint->id}", ['name' => 'Sprint one', 'goal' => 'New goal'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sprint one')
            ->assertJsonPath('data.goal', 'New goal');
    });

    it('refuses to change a completed sprint', function () {
        actingAsRole(RoleName::IncidentManager);
        $sprint = Sprint::factory()->completed()->create();

        $this->putJson("/api/v1/sprints/{$sprint->id}", ['name' => 'Nope'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'sprint_completed');
    });

    it('deletes a planned sprint and returns its tasks to the backlog', function () {
        actingAsRole(RoleName::IncidentManager);
        $sprint = Sprint::factory()->create();
        $task = Task::factory()->inSprint($sprint)->create();

        $this->deleteJson("/api/v1/sprints/{$sprint->id}")->assertNoContent();

        expect($task->fresh()->sprint_id)->toBeNull();
        $this->assertModelMissing($sprint);
    });

    it('refuses to delete an active sprint', function () {
        actingAsRole(RoleName::IncidentManager);
        $sprint = Sprint::factory()->create(['state' => SprintState::Active]);

        $this->deleteJson("/api/v1/sprints/{$sprint->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'sprint_not_planned');
    });
});
