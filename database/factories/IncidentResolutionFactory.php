<?php

namespace Database\Factories;

use App\Enums\FixType;
use App\Models\Incident;
use App\Models\IncidentResolution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentResolution>
 */
class IncidentResolutionFactory extends Factory
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
            'summary' => fake()->sentence(),
            'fix_type' => FixType::ConfigChange,
            'verification' => fake()->sentence(),
        ];
    }

    /**
     * Summary and fix type only: enough to resolve, not enough to close.
     */
    public function withoutVerification(): static
    {
        return $this->state(fn (array $attributes) => ['verification' => null]);
    }
}
