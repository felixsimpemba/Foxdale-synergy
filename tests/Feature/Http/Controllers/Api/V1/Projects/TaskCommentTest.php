<?php

use App\Enums\RoleName;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->author = userWithRole(RoleName::Responder);
    $this->project = Project::factory()->withMember($this->author)->create();
    $this->task = Task::factory()->forProject($this->project)->create([
        'reporter_id' => $this->author->id,
    ]);
});

it('posts a comment and shows it in the timeline', function () {
    Sanctum::actingAs($this->author, ['*']);

    $response = $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => 'Looks good to me',
    ])->assertCreated()
        ->assertJsonPath('data.body', 'Looks good to me')
        ->assertJsonPath('data.author.id', $this->author->id);

    $this->assertDatabaseHas(TaskComment::class, [
        'id' => $response->json('data.id'),
        'task_id' => $this->task->id,
        'user_id' => $this->author->id,
    ]);

    $this->getJson("/api/v1/tasks/{$this->task->id}/timeline")
        ->assertOk()
        ->assertJsonPath('data.0.type', 'comment')
        ->assertJsonPath('data.0.data.body', 'Looks good to me')
        ->assertJsonPath('data.0.actor.id', $this->author->id);
});

it('only lets project contributors comment', function () {
    $viewer = userWithRole(RoleName::Responder);
    $this->project->members()->syncWithoutDetaching([
        $viewer->id => ['role' => 'viewer'],
    ]);

    Sanctum::actingAs($viewer, ['*']);
    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => 'Nope'])
        ->assertForbidden();

    actingAsRole(RoleName::Reporter);
    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => 'Nope'])
        ->assertNotFound();
});

it('lets the author edit and delete a comment, and nobody else', function () {
    Sanctum::actingAs($this->author, ['*']);

    $id = $this->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => 'First'])
        ->assertCreated()
        ->json('data.id');

    $this->putJson("/api/v1/task-comments/{$id}", ['body' => 'Updated'])
        ->assertOk()
        ->assertJsonPath('data.body', 'Updated');

    expect(TaskComment::query()->findOrFail($id)->edited_at)->not->toBeNull();

    $other = User::factory()->create()->assignRole(RoleName::Responder->value);
    $this->project->members()->syncWithoutDetaching([
        $other->id => ['role' => 'member'],
    ]);
    Sanctum::actingAs($other, ['*']);

    $this->putJson("/api/v1/task-comments/{$id}", ['body' => 'Hijacked'])->assertForbidden();
    $this->deleteJson("/api/v1/task-comments/{$id}")->assertForbidden();

    Sanctum::actingAs($this->author, ['*']);
    $this->deleteJson("/api/v1/task-comments/{$id}")->assertNoContent();
    $this->assertSoftDeleted(TaskComment::class, ['id' => $id]);

    $timeline = $this->getJson("/api/v1/tasks/{$this->task->id}/timeline")->assertOk()->json('data');
    $comment = collect($timeline)->firstWhere('type', 'comment');

    expect($comment['data']['is_deleted'])->toBeTrue()
        ->and($comment['data']['body'])->toBeNull();
});

it('requires a body', function () {
    Sanctum::actingAs($this->author, ['*']);

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});
