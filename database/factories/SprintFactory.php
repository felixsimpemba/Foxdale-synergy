<?php

namespace Database\Factories;

use App\Enums\SprintState;
use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sprint>
 */
class SprintFactory extends Factory
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
            'name' => 'Sprint '.fake()->unique()->numberBetween(1, 100000),
            'goal' => fake()->sentence(),
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addWeeks(2)->toDateString(),
            'state' => SprintState::Planned,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => SprintState::Active,
            'starts_on' => now()->toDateString(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => SprintState::Completed,
            'completed_at' => now(),
        ]);
    }
}
