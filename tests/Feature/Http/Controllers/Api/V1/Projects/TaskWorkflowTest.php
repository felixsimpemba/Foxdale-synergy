<?php

use App\Enums\RoleName;
use App\Enums\TaskReviewState;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->manager = userWithRole(RoleName::IncidentManager);
    $this->author = userWithRole(RoleName::Responder);
    $this->reviewer = userWithRole(RoleName::Responder);

    $this->project = Project::factory()
        ->withMember($this->author)
        ->withMember($this->reviewer)
        ->create();
});

describe('moving cards', function () {
    it('moves a task between columns and logs the status change', function () {
        $task = Task::factory()->forProject($this->project)->create(['reporter_id' => $this->author->id]);

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/move", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        expect($task->fresh()->completed_at)->toBeNull();
        $this->assertDatabaseHas(TaskActivity::class, [
            'task_id' => $task->id,
            'type' => 'status_changed',
            'from_value' => 'todo',
            'to_value' => 'in_progress',
        ]);
    });

    it('places a card between two cards', function () {
        $sprint = Sprint::factory()->create(['project_id' => $this->project->id]);
        $first = Task::factory()->inSprint($sprint)->create(['position' => 1024]);
        $second = Task::factory()->inSprint($sprint)->create(['position' => 2048]);
        $third = Task::factory()->inSprint($sprint)->create(['position' => 3072]);

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$third->id}/move", [
            'status' => 'todo',
            'before_id' => $first->id,
            'after_id' => $second->id,
        ])->assertOk();

        expect($third->fresh()->position)->toBe(1536);
    });

    it('moves a card to another column and sprint', function () {
        $sprint = Sprint::factory()->create(['project_id' => $this->project->id]);
        $nextSprint = Sprint::factory()->create(['project_id' => $this->project->id]);
        $task = Task::factory()->inSprint($sprint)->create();

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/move", [
            'status' => 'in_progress',
            'sprint_id' => $nextSprint->id,
        ])->assertOk();

        expect($task->fresh()->sprint_id)->toBe($nextSprint->id)
            ->and($task->fresh()->status)->toBe(TaskStatus::InProgress);
        $this->assertDatabaseHas(TaskActivity::class, [
            'task_id' => $task->id,
            'type' => 'sprint_changed',
        ]);
    });

    it('hides tasks from people outside the project', function () {
        $task = Task::factory()->forProject($this->project)->create();
        actingAsRole(RoleName::Reporter);

        $this->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
        $this->getJson("/api/v1/tasks/{$task->id}/timeline")->assertNotFound();
        $this->postJson("/api/v1/tasks/{$task->id}/move", ['status' => 'in_progress'])->assertNotFound();
    });
});

describe('review workflow', function () {
    it('requires a reviewer and an approval before done', function () {
        $task = Task::factory()->forProject($this->project)->create(['reporter_id' => $this->author->id]);

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/move", ['status' => 'in_review'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reviewer_id');

        $this->postJson("/api/v1/tasks/{$task->id}/move", [
            'status' => 'in_review',
            'reviewer_id' => $this->reviewer->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.review_state', 'requested');

        $this->postJson("/api/v1/tasks/{$task->id}/move", ['status' => 'done'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'review_required');
    });

    it('lets the reviewer approve a task into done', function () {
        $task = Task::factory()->forProject($this->project)->create(['reporter_id' => $this->author->id]);

        Sanctum::actingAs($this->author, ['*']);
        $this->postJson("/api/v1/tasks/{$task->id}/request-review", [
            'reviewer_id' => $this->reviewer->id,
        ])->assertOk()->assertJsonPath('data.status', 'in_review');

        Sanctum::actingAs($this->reviewer, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/approve", ['note' => 'Looks good'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.review_state', 'approved');

        expect($task->fresh()->completed_at)->not->toBeNull();
        $this->assertDatabaseHas(TaskActivity::class, [
            'task_id' => $task->id,
            'type' => 'review_approved',
        ]);
    });

    it('does not let the author approve their own task', function () {
        $task = Task::factory()->forProject($this->project)->create([
            'reporter_id' => $this->manager->id,
            'reviewer_id' => $this->author->id,
            'status' => TaskStatus::InReview,
            'review_state' => TaskReviewState::Requested,
        ]);

        Sanctum::actingAs($this->manager, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/approve")
            ->assertForbidden()
            ->assertJsonPath('code', 'self_review');
    });

    it('returns a task to progress when changes are requested', function () {
        $task = Task::factory()->forProject($this->project)->create([
            'reporter_id' => $this->author->id,
            'reviewer_id' => $this->reviewer->id,
            'status' => TaskStatus::InReview,
            'review_state' => TaskReviewState::Requested,
        ]);

        Sanctum::actingAs($this->reviewer, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/request-changes", ['note' => 'Add tests'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.review_state', 'changes_requested');

        $this->assertDatabaseHas(TaskActivity::class, [
            'task_id' => $task->id,
            'type' => 'changes_requested',
        ]);
    });

    it('requires a note when asking for changes', function () {
        $task = Task::factory()->forProject($this->project)->create([
            'reviewer_id' => $this->reviewer->id,
            'status' => TaskStatus::InReview,
            'review_state' => TaskReviewState::Requested,
        ]);

        Sanctum::actingAs($this->reviewer, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/request-changes")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');
    });

    it('allows done directly when the project does not require review', function () {
        $project = Project::factory()->withMember($this->author)->create(['review_required' => false]);
        $task = Task::factory()->forProject($project)->create(['reporter_id' => $this->author->id]);

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/move", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    });

    it('does not let the author ask to review their own task', function () {
        $task = Task::factory()->forProject($this->project)->create(['reporter_id' => $this->author->id]);

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/request-review", [
            'reviewer_id' => $this->author->id,
        ])->assertForbidden()->assertJsonPath('code', 'self_review');
    });

    it('lets an assigned reviewer approve without the global review permission', function () {
        $author = userWithRole(RoleName::Reporter);
        $reviewer = userWithRole(RoleName::Reporter);
        $project = Project::factory()->withMember($author)->withMember($reviewer)->create();
        $task = Task::factory()->forProject($project)->create(['reporter_id' => $author->id]);

        Sanctum::actingAs($author, ['*']);
        $this->postJson("/api/v1/tasks/{$task->id}/request-review", [
            'reviewer_id' => $reviewer->id,
        ])->assertOk()->assertJsonPath('data.status', 'in_review');

        Sanctum::actingAs($reviewer, ['*']);
        $this->postJson("/api/v1/tasks/{$task->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    });

    it('only accepts a project member as reviewer', function () {
        $task = Task::factory()->forProject($this->project)->create(['reporter_id' => $this->author->id]);
        $stranger = User::factory()->create();

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/request-review", [
            'reviewer_id' => $stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
    });
});

describe('timeline', function () {
    it('returns task activity oldest first', function () {
        $task = Task::factory()->forProject($this->project)->create(['reporter_id' => $this->author->id]);

        Sanctum::actingAs($this->author, ['*']);

        $this->postJson("/api/v1/tasks/{$task->id}/move", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/v1/tasks/{$task->id}/move", ['status' => 'todo'])->assertOk();

        $this->getJson("/api/v1/tasks/{$task->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'status_changed')
            ->assertJsonPath('data.0.data.from', 'todo')
            ->assertJsonPath('data.0.data.to', 'in_progress')
            ->assertJsonPath('data.0.actor.id', $this->author->id)
            ->assertJsonPath('data.1.data.to', 'todo');
    });
});
