<?php

namespace Database\Factories;

use App\Models\PulseResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PulseResponse>
 */
class PulseResponseFactory extends Factory
{
    protected $model = PulseResponse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departments = array_keys(config('pulse.departments'));
        $department = (string) fake()->randomElement($departments);
        $others = array_values(array_diff($departments, [$department]));
        $ratings = [];

        foreach (array_slice($others, 0, 3) as $other) {
            $ratings[$other] = fake()->numberBetween(1, 5);
        }

        return [
            'department' => $department,
            'name' => fake()->name(),
            'role' => fake()->randomElement(['Head of department', 'Team lead', 'Supervisor', 'Analyst']),
            'answers' => [
                'teamSize' => fake()->numberBetween(3, 30),
                'shift' => fake()->randomElement(config('pulse.options.shift')),
                'markets' => [fake()->randomElement(config('pulse.options.markets'))],
                'duties' => fake()->sentence(),
                'tools' => fake()->randomElements(config('pulse.options.tools'), 2),
                'toolsOther' => '',
                'problems' => [[
                    'text' => fake()->sentence(10),
                    'freq' => fake()->randomElement(config('pulse.frequency')),
                    'impact' => fake()->randomElement(config('pulse.impact')),
                    'handling' => fake()->sentence(8),
                    'needs' => fake()->randomElement($others),
                ]],
                'reportsTo' => 'Head of Operations',
                'directReports' => 'Shift team',
                'escalation' => fake()->sentence(),
                'cadence' => fake()->randomElement(config('pulse.options.cadence')),
                'escList' => fake()->randomElement(config('pulse.options.escList')),
                'reportChannels' => [fake()->randomElement(config('pulse.options.reportChannels'))],
                'itHear' => [fake()->randomElement(config('pulse.options.itHear'))],
                'notice' => fake()->randomElement(config('pulse.options.notice')),
                'itSurprise' => fake()->sentence(),
                'itNeeds' => [fake()->randomElement(config('pulse.options.itNeeds'))],
                'watchFor' => fake()->sentence(),
                'ratings' => $ratings,
                'dependsOn' => $others[0] ?? null,
                'dependsOnUs' => $others[1] ?? null,
                'gap' => fake()->sentence(),
                'helps' => fake()->randomElements(config('pulse.options.helps'), 2),
                'oneThing' => fake()->sentence(),
                'anything' => '',
            ],
            'edit_token_hash' => PulseResponse::hashToken(bin2hex(random_bytes(32))),
        ];
    }

    /**
     * Give the response a known edit token so a test can sign in as its owner.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (): array => [
            'edit_token_hash' => PulseResponse::hashToken($token),
        ]);
    }
}
