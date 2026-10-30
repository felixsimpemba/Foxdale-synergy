<?php

namespace Database\Factories;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\ActionItemType;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open item with an owner, due in two weeks.
 *
 * @extends Factory<RcaActionItem>
 */
class RcaActionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rca_id' => Rca::factory(),
            'type' => ActionItemType::Preventive,
            'title' => fake()->sentence(6),
            'owner_id' => User::factory(),
            'due_date' => now()->addWeeks(2)->toDateString(),
            'priority' => ActionItemPriority::Medium,
            'status' => ActionItemStatus::Open,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => ['due_date' => now()->subDays(3)->toDateString()]);
    }

    public function unplanned(): static
    {
        return $this->state(fn (array $attributes) => ['owner_id' => null, 'due_date' => null]);
    }
}
