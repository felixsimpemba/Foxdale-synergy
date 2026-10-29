<?php

namespace Database\Factories;

use App\Models\Runbook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Runbook>
 */
class RunbookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => "## Steps\n\n1. ".fake()->sentence()."\n2. ".fake()->sentence(),
        ];
    }
}
