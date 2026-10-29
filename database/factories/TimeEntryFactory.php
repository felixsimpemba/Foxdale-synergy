<?php

namespace Database\Factories;

use App\Enums\TimeEntrySource;
use App\Models\Incident;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => User::factory(),
            'minutes' => fake()->numberBetween(5, 120),
            'note' => fake()->sentence(),
            'worked_on' => today(),
            'source' => TimeEntrySource::Manual,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'minutes' => null,
            'started_at' => now(),
            'source' => TimeEntrySource::Timer,
        ]);
    }
}
