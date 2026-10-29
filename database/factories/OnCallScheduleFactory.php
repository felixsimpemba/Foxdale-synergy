<?php

namespace Database\Factories;

use App\Enums\RotationType;
use App\Models\OnCallSchedule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnCallSchedule>
 */
class OnCallScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true).' rotation',
            'rotation_type' => RotationType::Weekly,
            'timezone' => 'UTC',
            'is_active' => true,
        ];
    }
}
