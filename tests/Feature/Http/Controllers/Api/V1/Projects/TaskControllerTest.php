<?php

use App\Enums\ProjectMemberRole;
use App\Enums\RoleName;
use App\Enums\SprintState;
use App\Enums\TaskActivityType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('creating tickets', function () {
    it('numbers tickets per project and stores the reference', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create(['key' => 'SYN']);
        $other = Project::factory()->create(['key' => 'OPS']);

        $first = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'First'])
            ->assertCreated()
            ->assertJsonPath('data.ref', 'SYN-1')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.number', 1);

        $second = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Second'])
            ->assertCreated()
            ->assertJsonPath('data.ref', 'SYN-2');

        $this->postJson("/api/v1/projects/{$other->id}/tasks", ['title' => 'Other'])
            ->assertCreated()
            ->assertJsonPath('data.ref', 'OPS-1');

        expect($first->json('data.position'))->toBe(1024)
            ->and($second->json('data.position'))->toBe(2048);
    });

    it('records the reporter and the created activity', function () {
        $actor = actingAsRole(RoleName::Reporter);
        $project = Project::factory()->ledBy($actor)->create();

        $response = $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Write the board',
            'description' => 'Drag and drop first',
        ])->assertCreated()
            ->assertJsonPath('data.reporter.id', $actor->id);

        $task = Task::query()->findOrFail($response->json('data.id'));

        expect($task->activities()->where('type', TaskActivityType::Created->value)->exists())->toBeTrue();
    });

    it('requires an active project member as assignee and reviewer', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $stranger = User::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Assigned outside the project',
            'assignee_id' => $stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');

        $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Review outside the project',
            'status' => 'in_review',
            'reviewer_id' => $stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
    });

    it('requires a reviewer when a task starts in review and forbids done', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Needs review',
            'status' => 'in_review',
        ])->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');

        $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Already done',
            'status' => 'done',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    });

    it('links subtasks and sprints from the same project only', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $otherSprint = Sprint::factory()->create(['project_id' => $other->id]);

        $parent = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Parent'])
            ->assertCreated()
            ->json('data');

        $child = $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Child',
            'parent_id' => $parent['id'],
            'sprint_id' => $sprint->id,
        ])->assertCreated()
            ->assertJsonPath('data.parent_id', $parent['id'])
            ->assertJsonPath('data.sprint_id', $sprint->id);

        $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Grandchild',
            'parent_id' => $child->json('data.id'),
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->postJson("/api/v1/projects/{$project->id}/tasks", [
            'title' => 'Wrong sprint',
            'sprint_id' => $otherSprint->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sprint_id');
    });
});

describe('listing and the board', function () {
    it('filters tickets by status, priority and text', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();

        Task::factory()->forProject($project)->create([
            'title' => 'Payments down',
            'priority' => TaskPriority::High,
        ]);
        Task::factory()->forProject($project)->inStatus(TaskStatus::InProgress)->create([
            'title' => 'Payments retry',
        ]);
        Task::factory()->forProject($project)->inStatus(TaskStatus::Done)->create([
            'title' => 'Unrelated work',
        ]);

        $this->getJson("/api/v1/projects/{$project->id}/tasks?status=todo,in_progress&priority=high")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Payments down');

        $this->getJson("/api/v1/projects/{$project->id}/tasks?q=Payments")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('groups the active sprint into four ordered columns', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id, 'state' => SprintState::Active]);

        $todo = Task::factory()->inSprint($sprint)->create(['position' => 1024]);
        $doing = Task::factory()->inSprint($sprint)->inStatus(TaskStatus::InProgress)->create(['position' => 1024]);
        Task::factory()->inSprint($sprint)->inStatus(TaskStatus::Done)->create();
        $backlogTask = Task::factory()->forProject($project)->create();
        Task::factory()->subtaskOf($todo)->inSprint($sprint)->create();

        $this->getJson("/api/v1/projects/{$project->id}/board")
            ->assertOk()
            ->assertJsonPath('data.sprint.id', $sprint->id)
            ->assertJsonPath('data.columns.0.status', 'todo')
            ->assertJsonPath('data.columns.0.count', 1)
            ->assertJsonPath('data.columns.0.tasks.0.id', $todo->id)
            ->assertJsonPath('data.columns.1.status', 'in_progress')
            ->assertJsonPath('data.columns.1.tasks.0.id', $doing->id)
            ->assertJsonPath('data.columns.2.status', 'in_review')
            ->assertJsonPath('data.columns.3.status', 'done');

        $this->getJson("/api/v1/projects/{$project->id}/board?sprint_id=backlog")
            ->assertOk()
            ->assertJsonPath('data.sprint', null)
            ->assertJsonPath('data.columns.0.tasks.0.id', $backlogTask->id);
    });

    it('falls back to the backlog when no sprint is active', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $task = Task::factory()->forProject($project)->create();

        $this->getJson("/api/v1/projects/{$project->id}/board")
            ->assertOk()
            ->assertJsonPath('data.sprint', null)
            ->assertJsonPath('data.columns.0.tasks.0.id', $task->id);
    });

    it('rejects a sprint from another project', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $otherSprint = Sprint::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}/board?sprint_id={$otherSprint->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sprint_id');
    });

    it('returns 404 to someone outside the project', function () {
        actingAsRole(RoleName::Reporter);
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}/tasks")->assertNotFound();
        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Nope'])->assertNotFound();
    });

    it('does not let a viewer contribute', function () {
        $viewer = userWithRole(RoleName::Responder);
        $project = Project::factory()->withMember($viewer, ProjectMemberRole::Viewer)->create();
        $task = Task::factory()->forProject($project)->create();
        Sanctum::actingAs($viewer, ['*']);

        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Nope'])->assertForbidden();
        $this->putJson("/api/v1/tasks/{$task->id}", ['title' => 'Nope'])->assertForbidden();
    });
});

describe('updating, showing and deleting', function () {
    it('records priority, due date, assignee and sprint changes', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $project->members()->syncWithoutDetaching([
            $member->id => ['role' => ProjectMemberRole::Member->value],
        ]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $task = Task::factory()->forProject($project)->create(['priority' => TaskPriority::Low]);

        $this->putJson("/api/v1/tasks/{$task->id}", [
            'priority' => 'high',
            'due_date' => '2026-12-01',
            'assignee_id' => $member->id,
            'sprint_id' => $sprint->id,
        ])->assertOk()
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.assignee.id', $member->id)
            ->assertJsonPath('data.sprint.id', $sprint->id);

        $types = $task->activities()->get()->map(fn ($activity) => $activity->type);

        expect($types)->toContain(
            TaskActivityType::PriorityChanged,
            TaskActivityType::DueDateChanged,
            TaskActivityType::Assigned,
            TaskActivityType::SprintChanged,
        );
    });

    it('does not allow status changes through the update endpoint', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $task = Task::factory()->forProject($project)->create();

        $this->putJson("/api/v1/tasks/{$task->id}", ['status' => 'done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    });

    it('shows subtasks and their counts', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $parent = Task::factory()->forProject($project)->create();
        Task::factory()->subtaskOf($parent)->create();
        Task::factory()->subtaskOf($parent)->inStatus(TaskStatus::Done)->create();

        $this->getJson("/api/v1/tasks/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('data.subtasks_count', 2)
            ->assertJsonPath('data.done_subtasks_count', 1)
            ->assertJsonCount(2, 'data.subtasks');
    });

    it('soft deletes a leaf task and never reuses its number', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create(['key' => 'SYN']);

        $first = $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'First'])
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/tasks/{$first}")->assertNoContent();
        $this->assertSoftDeleted(Task::class, ['id' => $first]);

        $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => 'Second'])
            ->assertCreated()
            ->assertJsonPath('data.ref', 'SYN-2');
    });

    it('refuses to delete a task that still has subtasks', function () {
        actingAsRole(RoleName::IncidentManager);
        $project = Project::factory()->create();
        $parent = Task::factory()->forProject($project)->create();
        Task::factory()->subtaskOf($parent)->create();

        $this->deleteJson("/api/v1/tasks/{$parent->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'task_has_subtasks');
    });
});
