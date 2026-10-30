<?php

namespace Database\Factories;

use App\Enums\IncidentUpdateType;
use App\Enums\UpdateVisibility;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentUpdate>
 */
class IncidentUpdateFactory extends Factory
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
            'type' => IncidentUpdateType::Comment,
            'body' => fake()->sentence(),
            'visibility' => UpdateVisibility::Internal,
        ];
    }
}
