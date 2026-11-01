<?php

use App\Enums\NotificationEvent;
use App\Enums\RoleName;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\InAppAlert;
use App\Notifications\TaskNotice;
use Illuminate\Support\Facades\Notification;
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

it('threads replies under the comment they answer', function () {
    Sanctum::actingAs($this->author, ['*']);

    $parentId = $this->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => 'First'])
        ->assertCreated()
        ->json('data.id');

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => 'Answer',
        'parent_id' => $parentId,
    ])->assertCreated()
        ->assertJsonPath('data.parent_id', $parentId);

    $thread = collect($this->getJson("/api/v1/tasks/{$this->task->id}/timeline")->json('data'))
        ->firstWhere('type', 'comment');

    expect($thread['data']['replies'])->toHaveCount(1)
        ->and($thread['data']['replies'][0]['body'])->toBe('Answer')
        ->and($thread['data']['replies'][0]['parent_id'])->toBe($parentId)
        ->and($thread['data']['last_activity_at'])->not->toBeNull();
});

it('rejects replies to replies and replies from another task', function () {
    Sanctum::actingAs($this->author, ['*']);

    $parentId = $this->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => 'First'])->json('data.id');
    $replyId = $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => 'Answer',
        'parent_id' => $parentId,
    ])->json('data.id');

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => 'Too deep',
        'parent_id' => $replyId,
    ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

    $otherTask = Task::factory()->forProject($this->project)->create();
    $foreign = TaskComment::factory()->for($otherTask)->create();

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => 'Wrong task',
        'parent_id' => $foreign->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
});

it('stores a mention, shows it on the timeline and tells the person', function () {
    Notification::fake();
    Sanctum::actingAs($this->author, ['*']);

    $ada = userWithRole(RoleName::Responder);
    $this->project->members()->syncWithoutDetaching([$ada->id => ['role' => 'member']]);

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => "@{$ada->name} the queue is backing up, can you look?",
    ])->assertCreated()
        ->assertJsonPath('data.mentions.0.id', $ada->id)
        ->assertJsonPath('data.mentions.0.name', $ada->name);

    Notification::assertSentTo($ada, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskMention);
    Notification::assertSentTo($ada, TaskNotice::class, fn (TaskNotice $mail) => str_contains($mail->subjectLine, $this->task->ref));
    Notification::assertNotSentTo($this->author, InAppAlert::class);

    $thread = collect($this->getJson("/api/v1/tasks/{$this->task->id}/timeline")->json('data'))
        ->firstWhere('type', 'comment');

    expect($thread['data']['mentions'])->toBe([['id' => $ada->id, 'name' => $ada->name]]);
});

it('does not mention somebody who cannot open the project', function () {
    Notification::fake();
    Sanctum::actingAs($this->author, ['*']);

    $outsider = User::factory()->create();

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => "@{$outsider->name} can you look?",
    ])->assertCreated()->assertJsonPath('data.mentions', []);

    Notification::assertNotSentTo($outsider, InAppAlert::class);
    expect(TaskComment::query()->latest('id')->firstOrFail()->mentions()->count())->toBe(0);
});

it('lists who can be mentioned on a task', function () {
    $member = userWithRole(RoleName::Responder);
    $this->project->members()->syncWithoutDetaching([$member->id => ['role' => 'member']]);

    Sanctum::actingAs($this->author, ['*']);

    $ids = collect($this->getJson("/api/v1/tasks/{$this->task->id}/mentionable")->assertOk()->json('data'))
        ->pluck('id');

    expect($ids)->toContain($this->author->id, $member->id);

    Sanctum::actingAs(userWithRole(RoleName::Reporter), ['*']);
    $this->getJson("/api/v1/tasks/{$this->task->id}/mentionable")->assertNotFound();
});
