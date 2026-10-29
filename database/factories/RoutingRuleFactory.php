<?php

namespace Database\Factories;

use App\Enums\AssignmentMode;
use App\Models\RoutingRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A catch-all rule by default; narrow it with state.
 *
 * @extends Factory<RoutingRule>
 */
class RoutingRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'priority' => fake()->numberBetween(10, 90),
            'team_id' => Team::factory(),
            'assignment_mode' => AssignmentMode::OnCall,
            'is_active' => true,
        ];
    }
}
