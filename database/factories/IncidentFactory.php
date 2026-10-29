<?php

namespace Database\Factories;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds incidents directly, without routing or SLA targets. Use the CreateIncident action
 * (or POST /incidents) when a test depends on those.
 *
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'severity' => Severity::Sev3,
            'status' => IncidentStatus::Reported,
            'source' => IncidentSource::Manual,
            'reporter_id' => User::factory(),
            'reported_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Incident $incident): void {
            $incident->forceFill(['ref' => Incident::makeRef($incident->id, $incident->reported_at)])->saveQuietly();
            $incident->statusHistory()->create([
                'to_status' => IncidentStatus::Reported,
                'changed_at' => $incident->reported_at,
            ]);
        });
    }

    public function severity(Severity $severity): static
    {
        return $this->state(fn (array $attributes) => ['severity' => $severity]);
    }

    /**
     * Put the incident on a service owned by the team, and route it there.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
            'service_id' => Service::factory()->for($team, 'ownerTeam'),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => ['assignee_id' => $user->id]);
    }

    /**
     * Set the status directly (no history rows beyond "reported"). For tests that only need
     * an incident to be in a state; use TransitionIncident to test the transitions themselves.
     */
    public function inStatus(IncidentStatus $status): static
    {
        return $this->state(function (array $attributes) use ($status) {
            $reachedAck = ! in_array($status, [IncidentStatus::Reported, IncidentStatus::Cancelled], true);
            $reachedResolve = in_array($status, [IncidentStatus::Resolved, IncidentStatus::Closed], true);

            return [
                'status' => $status,
                'acknowledged_at' => $reachedAck ? now() : null,
                'resolved_at' => $reachedResolve ? now() : null,
                'closed_at' => $status === IncidentStatus::Closed ? now() : null,
            ];
        });
    }
}
