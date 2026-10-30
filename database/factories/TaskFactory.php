<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds tasks directly, without the numbering and activity entries that TaskService creates.
 * Use POST /projects/{project}/tasks, or TaskService, when a test depends on those.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'type' => TaskType::Task,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'reporter_id' => User::factory(),
            'position' => 1024,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Task $task): void {
            if ($task->number !== null && $task->ref !== null) {
                return;
            }

            $number = (int) Task::withTrashed()->where('project_id', $task->project_id)->max('number') + 1;
            $projectKey = (string) Project::query()->whereKey($task->project_id)->value('key');

            $task->forceFill([
                'number' => $number,
                'ref' => Task::makeRef($projectKey, $number),
            ])->saveQuietly();
        });
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => ['project_id' => $project->id]);
    }

    public function inSprint(Sprint $sprint): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $sprint->project_id,
            'sprint_id' => $sprint->id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['assignee_id' => $user->id]);
    }

    public function inStatus(TaskStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'completed_at' => $status === TaskStatus::Done ? now() : null,
        ]);
    }

    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $parent->project_id,
            'parent_id' => $parent->id,
        ]);
    }
}
