<?php

namespace Database\Factories;

use App\Enums\ServiceCriticality;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'owner_team_id' => Team::factory(),
            'criticality' => fake()->randomElement(ServiceCriticality::cases()),
            'runbook_url' => fake()->url(),
            'is_active' => true,
        ];
    }
}
