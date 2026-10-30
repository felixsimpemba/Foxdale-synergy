<?php

namespace Database\Factories;

use App\Enums\EscalationTargetType;
use App\Models\EscalationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalationPolicy>
 */
class EscalationPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' escalation',
            'description' => fake()->sentence(),
        ];
    }

    /**
     * The example policy from the specification: on-call at once, team lead after 10 minutes,
     * then the given role (incident managers) after 20 minutes.
     */
    public function withStandardLevels(int $roleId): static
    {
        return $this->afterCreating(function (EscalationPolicy $policy) use ($roleId): void {
            $policy->levels()->createMany([
                ['level' => 1, 'after_minutes' => 0, 'target_type' => EscalationTargetType::OnCall],
                ['level' => 2, 'after_minutes' => 10, 'target_type' => EscalationTargetType::TeamLead],
                ['level' => 3, 'after_minutes' => 20, 'target_type' => EscalationTargetType::Role, 'target_id' => $roleId],
            ]);
        });
    }
}
