<?php

use App\Enums\NotificationEvent;
use App\Enums\RoleName;
use App\Enums\TaskReviewState;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\InAppAlert;
use App\Notifications\TaskNotice;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();

    $this->manager = userWithRole(RoleName::Responder);
    $this->project = Project::factory()->withMember($this->manager)->create();
    $this->task = Task::factory()->forProject($this->project)->create([
        'reporter_id' => $this->manager->id,
    ]);

    Sanctum::actingAs($this->manager, ['*']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function projectMember(Project $project, array $attributes = []): User
{
    $member = userWithRole(RoleName::Responder, $attributes);
    $project->members()->syncWithoutDetaching([$member->id => ['role' => 'member']]);

    return $member;
}

it('tells the new assignee, but not someone who assigned it to themselves', function () {
    $assignee = projectMember($this->project);

    $this->postJson("/api/v1/tasks/{$this->task->id}/assign", ['assignee_id' => $assignee->id])
        ->assertOk();

    Notification::assertSentTo($assignee, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskAssigned);
    Notification::assertSentTo($assignee, TaskNotice::class, fn (TaskNotice $mail) => str_contains($mail->subjectLine, $this->task->ref));
    Notification::assertNotSentTo($this->manager, InAppAlert::class);

    Notification::fake();
    $this->postJson("/api/v1/tasks/{$this->task->id}/assign", ['assignee_id' => $this->manager->id])
        ->assertOk();

    Notification::assertNothingSent();
});

it('tells the reviewer when a task is ready for their decision', function () {
    $reviewer = projectMember($this->project);

    $this->postJson("/api/v1/tasks/{$this->task->id}/request-review", ['reviewer_id' => $reviewer->id])
        ->assertOk();

    Notification::assertSentTo($reviewer, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskReviewRequested);
    Notification::assertNotSentTo($this->manager, InAppAlert::class);
});

it('tells the assignee and reporter when changes are requested', function () {
    $assignee = projectMember($this->project);
    $reviewer = projectMember($this->project);

    $this->task->forceFill([
        'assignee_id' => $assignee->id,
        'reviewer_id' => $reviewer->id,
        'review_state' => TaskReviewState::Requested,
        'status' => TaskStatus::InReview,
    ])->save();

    Sanctum::actingAs($reviewer, ['*']);

    $this->postJson("/api/v1/tasks/{$this->task->id}/request-changes", ['note' => 'Add tests'])
        ->assertOk();

    Notification::assertSentTo($assignee, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskChangesRequested);
    Notification::assertSentTo($this->manager, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskChangesRequested);
    Notification::assertNotSentTo($reviewer, InAppAlert::class);
});

it('tells the assignee and reporter when a task is approved', function () {
    $assignee = projectMember($this->project);
    $reviewer = projectMember($this->project);

    $this->task->forceFill([
        'assignee_id' => $assignee->id,
        'reviewer_id' => $reviewer->id,
        'review_state' => TaskReviewState::Requested,
        'status' => TaskStatus::InReview,
    ])->save();

    Sanctum::actingAs($reviewer, ['*']);

    $this->postJson("/api/v1/tasks/{$this->task->id}/approve")->assertOk();

    Notification::assertSentTo($assignee, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskApproved);
    Notification::assertSentTo($this->manager, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskApproved);
});

it('tells the assignee about a new comment and the author about a reply', function () {
    $author = projectMember($this->project);
    $this->task->forceFill(['assignee_id' => $author->id])->save();

    $parentId = $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => 'Please pick this up',
    ])->assertCreated()->json('data.id');

    Notification::assertSentTo($author, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskComment);

    Notification::fake();
    Sanctum::actingAs($author, ['*']);

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => 'On it',
        'parent_id' => $parentId,
    ])->assertCreated();

    Notification::assertSentTo($this->manager, InAppAlert::class, fn (InAppAlert $alert) => $alert->event === NotificationEvent::TaskReply);
});

it('sends one notification when a comment both mentions and concerns the assignee', function () {
    $assignee = projectMember($this->project);
    $this->task->forceFill(['assignee_id' => $assignee->id])->save();

    $this->postJson("/api/v1/tasks/{$this->task->id}/comments", [
        'body' => "@{$assignee->name} can you look?",
    ])->assertCreated();

    Notification::assertSentTo($assignee, InAppAlert::class);
    Notification::assertSentToTimes($assignee, InAppAlert::class, 1);
});
