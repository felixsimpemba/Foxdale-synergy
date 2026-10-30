<?php

namespace Database\Factories;

use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => strtoupper(fake()->unique()->bothify('??##')),
            'name' => fake()->unique()->company().' Project',
            'description' => fake()->sentence(),
            'status' => ProjectStatus::Active,
            'review_required' => true,
            'lead_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            if ($project->lead_id !== null) {
                $project->members()->syncWithoutDetaching([
                    $project->lead_id => ['role' => ProjectMemberRole::Manager->value],
                ]);
            }
        });
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes): array => ['owner_team_id' => $team->id]);
    }

    public function ledBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['lead_id' => $user->id]);
    }

    public function withMember(User $user, ProjectMemberRole $role = ProjectMemberRole::Member): static
    {
        return $this->afterCreating(function (Project $project) use ($user, $role): void {
            $project->members()->syncWithoutDetaching([
                $user->id => ['role' => $role->value],
            ]);
        });
    }
}
