<?php

namespace Database\Factories;

use App\Models\WhatsAppGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppGroup>
 */
class WhatsAppGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_jid' => fake()->unique()->numerify('120363#############').'@g.us',
            'subject' => fake()->words(2, true),
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => true]);
    }
}
