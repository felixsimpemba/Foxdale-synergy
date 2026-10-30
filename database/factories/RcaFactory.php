<?php

namespace Database\Factories;

use App\Enums\RcaStatus;
use App\Enums\RecurrenceRisk;
use App\Enums\RootCauseCategory;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft with a root cause written. Add action items (RcaActionItemFactory) before it can
 * be sent for review.
 *
 * @extends Factory<Rca>
 */
class RcaFactory extends Factory
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
            'author_id' => User::factory(),
            'summary' => fake()->sentence(),
            'impact' => fake()->sentence(),
            'detection' => fake()->sentence(),
            'root_cause' => fake()->sentence(),
            'root_cause_category' => RootCauseCategory::Configuration,
            'five_whys' => [['why' => 'Why did payments fail?', 'answer' => 'The consumer stopped.']],
            'recurrence_risk' => RecurrenceRisk::Medium,
        ];
    }

    public function inStatus(RcaStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'submitted_at' => $status === RcaStatus::Draft ? null : now(),
            'approved_at' => $status === RcaStatus::Approved ? now() : null,
        ]);
    }

    public function withoutRootCause(): static
    {
        return $this->state(fn (array $attributes) => ['root_cause' => null, 'root_cause_category' => null]);
    }
}
