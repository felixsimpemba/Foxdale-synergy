<?php

namespace Database\Factories;

use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A shift covering now by default.
 *
 * @extends Factory<OnCallShift>
 */
class OnCallShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'on_call_schedule_id' => OnCallSchedule::factory(),
            'user_id' => User::factory(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHours(6),
            'is_override' => false,
        ];
    }

    public function override(): static
    {
        return $this->state(fn (array $attributes) => ['is_override' => true]);
    }
}
