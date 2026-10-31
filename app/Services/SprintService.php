<?php

namespace App\Services;

use App\Enums\SprintState;
use App\Enums\TaskStatus;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The sprint lifecycle: planned → active → completed. One sprint can be active per project;
 * completing a sprint sends unfinished work to the backlog or another planned sprint.
 */
class SprintService
{
    public function __construct(
        private TaskService $tasks,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function start(Sprint $sprint, User $actor, array $data): Sprint
    {
        return DB::transaction(function () use ($sprint, $actor, $data): Sprint {
            if ($sprint->state !== SprintState::Planned) {
                throw new ApiException('Only a planned sprint can be started.', 'sprint_not_planned', 409);
            }

            // Serialise starts for this project so two sprints cannot become active together.
            $project = Project::query()->whereKey($sprint->project_id)->lockForUpdate()->firstOrFail();
            $alreadyActive = Sprint::query()
                ->where('project_id', $project->id)
                ->where('state', SprintState::Active->value)
                ->exists();

            if ($alreadyActive) {
                throw new ApiException('Another sprint is already active. Complete it first.', 'sprint_already_active', 409);
            }

            $sprint->forceFill([
                'state' => SprintState::Active,
                'starts_on' => $data['starts_on'] ?? $sprint->starts_on ?? today(),
                'ends_on' => $data['ends_on'] ?? $sprint->ends_on,
                'completed_at' => null,
            ])->save();

            $this->audit->record('sprint.started', $sprint, actor: $actor);

            return $sprint->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(Sprint $sprint, User $actor, array $data): Sprint
    {
        return DB::transaction(function () use ($sprint, $actor, $data): Sprint {
            if ($sprint->state !== SprintState::Active) {
                throw new ApiException('Only the active sprint can be completed.', 'sprint_not_active', 409);
            }

            $target = $this->completionTarget($sprint, $data['move_unfinished_to'] ?? 'backlog');

            Task::query()
                ->where('sprint_id', $sprint->id)
                ->where('status', '!=', TaskStatus::Done->value)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->each(fn (Task $task) => $this->tasks->moveToSprint($task, $target, $actor));

            $sprint->forceFill([
                'state' => SprintState::Completed,
                'completed_at' => now(),
            ])->save();

            $this->audit->record('sprint.completed', $sprint, [
                'move_unfinished_to' => $target?->id,
            ], $actor);

            return $sprint->refresh();
        });
    }

    private function completionTarget(Sprint $sprint, string|int $value): ?Sprint
    {
        if ($value === 'backlog') {
            return null;
        }

        $target = Sprint::query()
            ->whereKey((int) $value)
            ->where('project_id', $sprint->project_id)
            ->first();

        if (
            $target === null
            || $target->id === $sprint->id
            || $target->state === SprintState::Completed
        ) {
            throw new ApiException('Choose the backlog or a planned sprint.', 'invalid_target_sprint');
        }

        return $target;
    }
}
