<?php

namespace App\Services;

use App\Enums\TaskActivityType;
use App\Enums\TaskReviewState;
use App\Enums\TaskStatus;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ticket numbering, board ordering and the review workflow. The API layer validates input;
 * this service enforces the rules that must hold whichever caller is used (drag, form, MCP).
 */
class TaskService
{
    /**
     * Gap between cards in a column, so a drop lands between two cards without a reindex.
     */
    private const POSITION_STEP = 1024;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, User $reporter, array $data): Task
    {
        return DB::transaction(function () use ($project, $reporter, $data): Task {
            // Lock the project so two people creating tickets cannot get the same number.
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $status = $this->status($data['status'] ?? TaskStatus::Todo);
            $sprintId = $data['sprint_id'] ?? null;

            if ($status === TaskStatus::Done) {
                throw new ApiException('A new task cannot start as done.', 'invalid_status');
            }

            $this->assertSprint($locked, $sprintId);
            $this->assertParent($locked, $data['parent_id'] ?? null);

            $number = (int) Task::withTrashed()->where('project_id', $locked->id)->max('number') + 1;

            $task = new Task([
                ...$data,
                'project_id' => $locked->id,
                'reporter_id' => $reporter->id,
            ]);
            $task->forceFill([
                'number' => $number,
                'ref' => Task::makeRef($locked->key, $number),
                'status' => $status,
                'position' => $this->nextPosition($locked->id, $sprintId, $status),
            ]);

            if ($status === TaskStatus::InReview) {
                if ($task->reviewer_id === null) {
                    throw new ApiException('Choose a reviewer before moving the task to review.', 'reviewer_required');
                }

                if ($task->reviewer_id === $task->reporter_id) {
                    throw new ApiException('You cannot review your own task.', 'self_review', 403);
                }

                $task->review_state = TaskReviewState::Requested;
            }

            $task->save();

            $this->record($task, $reporter, TaskActivityType::Created, null, $status->value);
            $this->recordAssignmentIfChanged($task, $reporter, null, $task->assignee_id);

            if ($status === TaskStatus::InReview) {
                $this->record($task, $reporter, TaskActivityType::ReviewRequested, null, (string) $task->reviewer_id, [
                    'reviewer_id' => $task->reviewer_id,
                    'reviewer_name' => $this->userName($task->reviewer_id),
                ]);
            }

            return $task->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Task $task, User $actor, array $data): Task
    {
        if (array_key_exists('status', $data)) {
            throw new ApiException('Move the task on the board to change its status.', 'status_not_editable');
        }

        return DB::transaction(function () use ($task, $actor, $data): Task {
            $project = Project::query()->findOrFail($task->project_id);
            $sprintId = array_key_exists('sprint_id', $data) ? $data['sprint_id'] : $task->sprint_id;

            $this->assertSprint($project, $sprintId);

            if (array_key_exists('parent_id', $data)) {
                $this->assertParent($project, $data['parent_id'], $task->id);
            }

            $before = [
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
                'assignee_id' => $task->assignee_id,
                'sprint_id' => $task->sprint_id,
            ];

            $task->fill($data);

            if (array_key_exists('sprint_id', $data) && $task->sprint_id !== $before['sprint_id']) {
                $task->position = $this->nextPosition($task->project_id, $task->sprint_id, $task->status);
            }

            if (array_key_exists('is_blocked', $data) && ! $task->is_blocked && ! array_key_exists('blocked_reason', $data)) {
                $task->blocked_reason = null;
            }

            $task->save();

            if ($task->priority !== $before['priority']) {
                $this->record($task, $actor, TaskActivityType::PriorityChanged, $before['priority']->value, $task->priority->value);
            }

            if ($task->due_date?->toDateString() !== $before['due_date']) {
                $this->record($task, $actor, TaskActivityType::DueDateChanged, $before['due_date'], $task->due_date?->toDateString());
            }

            $this->recordAssignmentIfChanged($task, $actor, $before['assignee_id'], $task->assignee_id);

            if ($task->sprint_id !== $before['sprint_id']) {
                $this->record($task, $actor, TaskActivityType::SprintChanged, $this->sprintName($before['sprint_id']), $this->sprintName($task->sprint_id), [
                    'from_sprint_id' => $before['sprint_id'],
                    'to_sprint_id' => $task->sprint_id,
                ]);
            }

            $changed = array_values(array_filter(
                ['title', 'description', 'type', 'story_points', 'is_blocked', 'blocked_reason'],
                fn (string $field): bool => $task->wasChanged($field),
            ));

            if ($changed !== []) {
                $this->record($task, $actor, TaskActivityType::Updated, null, null, ['fields' => $changed]);
            }

            return $task->refresh();
        });
    }

    /**
     * Drag-and-drop (or any status change). before_id and after_id describe the target column
     * neighbours; the server owns position so a filtered board cannot corrupt the order.
     *
     * @param  array<string, mixed>  $data
     */
    public function move(Task $task, User $actor, array $data): Task
    {
        $target = $this->status($data['status']);

        return DB::transaction(function () use ($task, $actor, $data, $target): Task {
            $project = Project::query()->findOrFail($task->project_id);
            $fromStatus = $task->status;
            $fromSprint = $task->sprint_id;
            $targetSprint = array_key_exists('sprint_id', $data) ? $data['sprint_id'] : $task->sprint_id;
            $reviewerId = $data['reviewer_id'] ?? $task->reviewer_id;

            $this->assertSprint($project, $targetSprint);

            if ($target === TaskStatus::InReview) {
                if ($reviewerId === null) {
                    throw new ApiException('Choose a reviewer before moving the task to review.', 'reviewer_required');
                }

                if ($reviewerId === $task->reporter_id) {
                    throw new ApiException('You cannot review your own task.', 'self_review', 403);
                }
            }

            if ($target === TaskStatus::Done && $project->review_required && $task->review_state !== TaskReviewState::Approved) {
                throw new ApiException('This task needs an approved review before it can be done.', 'review_required', 409);
            }

            $task->forceFill([
                'status' => $target,
                'sprint_id' => $targetSprint,
                'completed_at' => $target === TaskStatus::Done ? now() : null,
            ]);

            if ($target === TaskStatus::InReview) {
                $task->reviewer_id = $reviewerId;
                $task->review_state = TaskReviewState::Requested;
            } elseif ($fromStatus === TaskStatus::InReview || $fromStatus === TaskStatus::Done) {
                // Leaving review or reopening invalidates the previous decision.
                $task->review_state = TaskReviewState::None;
            }

            $task->position = $this->positionFor(
                $task,
                $targetSprint,
                $target,
                $data['before_id'] ?? null,
                $data['after_id'] ?? null,
            );
            $task->save();

            if ($fromStatus !== $target) {
                $this->record($task, $actor, TaskActivityType::StatusChanged, $fromStatus->value, $target->value);
            }

            if ($fromSprint !== $targetSprint) {
                $this->record($task, $actor, TaskActivityType::SprintChanged, $this->sprintName($fromSprint), $this->sprintName($targetSprint), [
                    'from_sprint_id' => $fromSprint,
                    'to_sprint_id' => $targetSprint,
                ]);
            }

            if ($target === TaskStatus::InReview) {
                $this->record($task, $actor, TaskActivityType::ReviewRequested, null, (string) $reviewerId, [
                    'reviewer_id' => $reviewerId,
                    'reviewer_name' => $this->userName($reviewerId),
                ]);
            }

            return $task->refresh();
        });
    }

    public function assign(Task $task, User $actor, ?User $assignee): Task
    {
        if ($task->assignee_id === $assignee?->id) {
            return $task;
        }

        $from = $task->assignee_id;
        $task->forceFill(['assignee_id' => $assignee?->id])->save();
        $this->recordAssignmentIfChanged($task, $actor, $from, $task->assignee_id);

        return $task->refresh();
    }

    public function requestReview(Task $task, User $actor, User $reviewer): Task
    {
        return DB::transaction(function () use ($task, $actor, $reviewer): Task {
            $project = Project::query()->findOrFail($task->project_id);

            if ($task->isFinished()) {
                throw new ApiException('Reopen the task before asking for review.', 'task_done', 409);
            }

            if ($reviewer->id === $task->reporter_id) {
                throw new ApiException('You cannot review your own task.', 'self_review', 403);
            }

            if (! $project->members()->whereKey($reviewer->id)->exists()) {
                throw new ApiException('Choose a member of this project.', 'invalid_reviewer');
            }

            $from = $task->status;
            $task->forceFill([
                'reviewer_id' => $reviewer->id,
                'review_state' => TaskReviewState::Requested,
                'status' => TaskStatus::InReview,
                'completed_at' => null,
                'position' => $this->nextPosition($task->project_id, $task->sprint_id, TaskStatus::InReview, $task->id),
            ])->save();

            if ($from !== TaskStatus::InReview) {
                $this->record($task, $actor, TaskActivityType::StatusChanged, $from->value, TaskStatus::InReview->value);
            }

            $this->record($task, $actor, TaskActivityType::ReviewRequested, null, (string) $reviewer->id, [
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
            ]);

            return $task->refresh();
        });
    }

    public function approve(Task $task, User $actor, ?string $note = null): Task
    {
        return DB::transaction(function () use ($task, $actor, $note): Task {
            $this->assertCanReview($task, $actor);

            if ($task->reporter_id === $actor->id) {
                throw new ApiException('You cannot approve your own task.', 'self_review', 403);
            }

            if ($task->review_state !== TaskReviewState::Requested) {
                throw new ApiException('This task is not waiting for review.', 'not_in_review', 409);
            }

            $from = $task->status;
            $task->forceFill([
                'review_state' => TaskReviewState::Approved,
                'status' => TaskStatus::Done,
                'completed_at' => now(),
                'position' => $this->nextPosition($task->project_id, $task->sprint_id, TaskStatus::Done, $task->id),
            ])->save();

            if ($from !== TaskStatus::Done) {
                $this->record($task, $actor, TaskActivityType::StatusChanged, $from->value, TaskStatus::Done->value);
            }

            $this->record($task, $actor, TaskActivityType::ReviewApproved, null, null, array_filter(['note' => $note]));

            return $task->refresh();
        });
    }

    public function requestChanges(Task $task, User $actor, string $note): Task
    {
        return DB::transaction(function () use ($task, $actor, $note): Task {
            $this->assertCanReview($task, $actor);

            if ($task->reporter_id === $actor->id) {
                throw new ApiException('You cannot review your own task.', 'self_review', 403);
            }

            if ($task->review_state !== TaskReviewState::Requested) {
                throw new ApiException('This task is not waiting for review.', 'not_in_review', 409);
            }

            $from = $task->status;
            $task->forceFill([
                'review_state' => TaskReviewState::ChangesRequested,
                'status' => TaskStatus::InProgress,
                'completed_at' => null,
                'position' => $this->nextPosition($task->project_id, $task->sprint_id, TaskStatus::InProgress, $task->id),
            ])->save();

            if ($from !== TaskStatus::InProgress) {
                $this->record($task, $actor, TaskActivityType::StatusChanged, $from->value, TaskStatus::InProgress->value);
            }

            $this->record($task, $actor, TaskActivityType::ChangesRequested, null, null, ['note' => $note]);

            return $task->refresh();
        });
    }

    /**
     * Move a task between sprints, used when a sprint is completed. The task keeps its
     * status and lands at the end of the matching column in the target sprint.
     */
    public function moveToSprint(Task $task, ?Sprint $sprint, ?User $actor = null): Task
    {
        if ($sprint !== null) {
            $project = Project::query()->findOrFail($task->project_id);
            $this->assertSprint($project, $sprint->id);
        }

        if ($task->sprint_id === $sprint?->id) {
            return $task;
        }

        $from = $task->sprint_id;

        $task->forceFill([
            'sprint_id' => $sprint?->id,
            'position' => $this->nextPosition($task->project_id, $sprint?->id, $task->status, $task->id),
        ])->save();

        $this->record($task, $actor, TaskActivityType::SprintChanged, $this->sprintName($from), $this->sprintName($sprint?->id), [
            'from_sprint_id' => $from,
            'to_sprint_id' => $sprint?->id,
        ]);

        return $task->refresh();
    }

    public function delete(Task $task): void
    {
        if ($task->subtasks()->exists()) {
            throw new ApiException('Delete or move its subtasks first.', 'task_has_subtasks', 409);
        }

        $task->delete();
    }

    private function assertCanReview(Task $task, User $actor): void
    {
        $project = Project::query()->findOrFail($task->project_id);

        if ($task->reviewer_id !== $actor->id && ! $project->canBeManagedBy($actor)) {
            throw new ApiException('Only the assigned reviewer can decide this task.', 'not_reviewer', 403);
        }
    }

    private function status(TaskStatus|string $status): TaskStatus
    {
        return $status instanceof TaskStatus ? $status : TaskStatus::from($status);
    }

    private function nextPosition(int $projectId, ?int $sprintId, TaskStatus $status, ?int $excludeId = null): int
    {
        $max = (int) Task::query()
            ->where('project_id', $projectId)
            ->where('status', $status->value)
            ->when(
                $sprintId === null,
                fn (Builder $query) => $query->whereNull('sprint_id'),
                fn (Builder $query) => $query->where('sprint_id', $sprintId),
            )
            ->when($excludeId !== null, fn (Builder $query) => $query->where('id', '!=', $excludeId))
            ->max('position');

        return $max + self::POSITION_STEP;
    }

    /**
     * The position for a card dropped between before_id and after_id (either may be null).
     */
    private function positionFor(Task $task, ?int $sprintId, TaskStatus $status, ?int $beforeId, ?int $afterId): int
    {
        if ($beforeId === $task->id || $afterId === $task->id) {
            throw new ApiException('A card cannot be placed next to itself.', 'invalid_position');
        }

        $column = $this->column($task->project_id, $sprintId, $status, $task->id);
        $prev = null;
        $next = null;

        if ($beforeId !== null && $afterId !== null) {
            $before = $column->firstWhere('id', $beforeId);
            $after = $column->firstWhere('id', $afterId);

            if ($before === null || $after === null) {
                throw new ApiException('Those cards are not in the target column.', 'invalid_position');
            }

            $prev = $before->position;
            $next = $after->position;

            if ($next < $prev) {
                throw new ApiException('Those cards are not in the expected order.', 'invalid_position');
            }
        } elseif ($beforeId !== null) {
            $index = $column->search(fn (Task $row): bool => $row->id === $beforeId);

            if ($index === false) {
                throw new ApiException('That card is not in the target column.', 'invalid_position');
            }

            $prev = $column[$index]->position;
            $next = $column->get($index + 1)?->position;
        } elseif ($afterId !== null) {
            $index = $column->search(fn (Task $row): bool => $row->id === $afterId);

            if ($index === false) {
                throw new ApiException('That card is not in the target column.', 'invalid_position');
            }

            $next = $column[$index]->position;
            $prev = $index > 0 ? $column[$index - 1]->position : null;
        } else {
            $prev = $column->last()?->position;
        }

        if ($prev === null && $next === null) {
            return self::POSITION_STEP;
        }

        if ($prev === null) {
            if ($next <= 1) {
                $this->reindex($column);

                return $this->positionFor($task, $sprintId, $status, $beforeId, $afterId);
            }

            return max(1, intdiv((int) $next, 2));
        }

        if ($next === null) {
            return $prev + self::POSITION_STEP;
        }

        if ($next - $prev <= 1) {
            $this->reindex($column);

            return $this->positionFor($task, $sprintId, $status, $beforeId, $afterId);
        }

        return intdiv((int) $prev + (int) $next, 2);
    }

    /**
     * @return Collection<int, Task>
     */
    private function column(int $projectId, ?int $sprintId, TaskStatus $status, int $excludeId): Collection
    {
        return Task::query()
            ->where('project_id', $projectId)
            ->where('status', $status->value)
            ->when(
                $sprintId === null,
                fn (Builder $query) => $query->whereNull('sprint_id'),
                fn (Builder $query) => $query->where('sprint_id', $sprintId),
            )
            ->where('id', '!=', $excludeId)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'position']);
    }

    /**
     * @param  Collection<int, Task>  $column
     */
    private function reindex(Collection $column): void
    {
        $position = self::POSITION_STEP;

        foreach ($column as $row) {
            Task::query()->whereKey($row->id)->update(['position' => $position]);
            $position += self::POSITION_STEP;
        }
    }

    private function assertSprint(Project $project, ?int $sprintId): void
    {
        if ($sprintId === null) {
            return;
        }

        if (! Sprint::query()->whereKey($sprintId)->where('project_id', $project->id)->exists()) {
            throw new ApiException('That sprint belongs to another project.', 'invalid_sprint');
        }
    }

    private function assertParent(Project $project, ?int $parentId, ?int $taskId = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Task::query()->whereKey($parentId)->where('project_id', $project->id)->first();

        if ($parent === null) {
            throw new ApiException('That parent task is not in this project.', 'invalid_parent');
        }

        if ($parent->parent_id !== null) {
            throw new ApiException('Subtasks cannot have their own subtasks.', 'nesting_too_deep');
        }

        if ($taskId === null) {
            return;
        }

        if ($parent->id === $taskId) {
            throw new ApiException('A task cannot be its own parent.', 'invalid_parent');
        }

        if (Task::query()->whereKey($taskId)->whereHas('subtasks')->exists()) {
            throw new ApiException('A task with subtasks cannot become a subtask.', 'has_subtasks');
        }
    }

    private function recordAssignmentIfChanged(Task $task, ?User $actor, ?int $fromId, ?int $toId): void
    {
        if ($fromId === $toId) {
            return;
        }

        $this->record($task, $actor, TaskActivityType::Assigned, $this->userName($fromId), $this->userName($toId), [
            'from_user_id' => $fromId,
            'to_user_id' => $toId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function record(Task $task, ?User $actor, TaskActivityType $type, ?string $from = null, ?string $to = null, array $meta = []): void
    {
        TaskActivity::create([
            'task_id' => $task->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'from_value' => $from,
            'to_value' => $to,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function userName(?int $userId): ?string
    {
        return $userId === null ? null : User::query()->whereKey($userId)->value('name');
    }

    private function sprintName(?int $sprintId): string
    {
        if ($sprintId === null) {
            return 'Backlog';
        }

        return (string) Sprint::query()->whereKey($sprintId)->value('name');
    }
}
