<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskComment>
 */
class TaskCommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(12),
        ];
    }

    public function replyTo(TaskComment $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_id' => $parent->task_id,
            'parent_id' => $parent->id,
        ]);
    }
}
