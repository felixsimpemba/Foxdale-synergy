<?php

use App\Enums\RoleName;
use App\Models\PulseResponse;
use App\Notifications\PulseResponseSubmitted;
use App\Services\Pulse\PulseSurvey;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/**
 * A complete, valid submission. Tests override only the field they are exercising.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pulsePayload(array $overrides = []): array
{
    $departments = array_keys(config('pulse.departments'));
    $others = array_values(array_diff($departments, ['noc']));
    $ratings = [];

    foreach (array_slice($others, 0, 2) as $id) {
        $ratings[$id] = 4;
    }

    return array_replace_recursive([
        'department' => 'noc',
        'name' => 'Ada Banda',
        'role' => 'NOC Supervisor',
        'teamSize' => 8,
        'shift' => 'Shifts, 24/7',
        'markets' => ['Castlebet Zambia'],
        'duties' => 'Watch uptime and feeds.',
        'tools' => ['Monitoring dashboards', 'WhatsApp groups'],
        'toolsOther' => '',
        'problems' => [[
            'text' => 'Odds feed delays.',
            'freq' => 'Daily',
            'impact' => 'High',
            'handling' => 'Call IT on-call.',
            'needs' => 'it',
        ]],
        'reportsTo' => 'Head of Operations',
        'directReports' => '4 shift monitors',
        'escalation' => 'Monitor → shift lead → IT.',
        'cadence' => 'Per shift',
        'escList' => 'Yes, up to date',
        'reportChannels' => ['WhatsApp'],
        'itHear' => ['WhatsApp message'],
        'notice' => 'Same day',
        'itSurprise' => 'A release changed settlement.',
        'itNeeds' => ['What is changing'],
        'watchFor' => 'Failed deposits.',
        'ratings' => $ratings,
        'dependsOn' => 'it',
        'dependsOnUs' => 'callcenter',
        'gap' => 'No shared incident channel.',
        'helps' => ['One shared incident channel', 'Change and release calendar'],
        'oneThing' => 'One shared incident channel.',
        'anything' => '',
        'website' => '',
    ], $overrides);
}

describe('public survey', function () {
    it('serves the form configuration', function () {
        $this->getJson('/api/v1/pulse/config')
            ->assertOk()
            ->assertJsonCount(count(config('pulse.departments')), 'data.departments')
            ->assertJsonPath('data.departments.0.id', 'noc')
            ->assertJsonPath('data.limits.helps', 5);
    });

    it('accepts a response without an account and returns its edit token', function () {
        $this->postJson('/api/v1/pulse/responses', pulsePayload())
            ->assertCreated()
            ->assertJsonPath('data.response.name', 'Ada Banda')
            ->assertJsonPath('data.response.department_name', 'NOC – Monitoring')
            ->assertJsonPath('data.response.answers.problems.0.text', 'Odds feed delays.')
            ->assertJsonMissingPath('data.response.edit_token_hash')
            ->assertJsonPath('data.edit_token', fn (string $token): bool => strlen($token) === 64);

        $this->assertDatabaseHas(PulseResponse::class, [
            'department' => 'noc',
            'name' => 'Ada Banda',
            'role' => 'NOC Supervisor',
        ]);
    });

    it('rejects an incomplete response', function () {
        $this->postJson('/api/v1/pulse/responses', pulsePayload(['name' => '', 'role' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'role']);
    });

    it('requires at least one described problem', function () {
        $this->postJson('/api/v1/pulse/responses', pulsePayload([
            'problems' => [['text' => '', 'freq' => '', 'impact' => '', 'handling' => '', 'needs' => '']],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('problems');
    });

    it('rejects a filled honeypot without saving', function () {
        $this->postJson('/api/v1/pulse/responses', pulsePayload(['website' => 'https://spam.example']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('website');

        $this->assertDatabaseCount(PulseResponse::class, 0);
    });

    it('rejects a rating for the respondent’s own department', function () {
        $this->postJson('/api/v1/pulse/responses', pulsePayload([
            'ratings' => ['noc' => 5, 'it' => 3],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ratings');
    });

    it('rejects more than five chosen fixes', function () {
        $this->postJson('/api/v1/pulse/responses', pulsePayload([
            'helps' => array_slice(config('pulse.options.helps'), 0, 6),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('helps');
    });

    it('returns a saved response to the right edit token only', function () {
        $response = PulseResponse::factory()->withToken('right-token')->create();

        $this->getJson("/api/v1/pulse/responses/{$response->id}", ['X-Pulse-Token' => 'right-token'])
            ->assertOk()
            ->assertJsonPath('data.id', $response->id);

        $this->getJson("/api/v1/pulse/responses/{$response->id}", ['X-Pulse-Token' => 'wrong-token'])
            ->assertNotFound();
    });

    it('updates a response and rotates its edit token', function () {
        $response = PulseResponse::factory()->withToken('old-token')->create([
            'department' => 'noc',
            'name' => 'Ada Banda',
            'role' => 'NOC Supervisor',
        ]);

        $updated = $this->putJson(
            "/api/v1/pulse/responses/{$response->id}",
            pulsePayload(['name' => 'Ada B.']),
            ['X-Pulse-Token' => 'old-token'],
        )->assertOk()
            ->assertJsonPath('data.response.name', 'Ada B.')
            ->json('data.edit_token');

        expect($updated)->toBeString()->toHaveLength(64);

        $this->getJson("/api/v1/pulse/responses/{$response->id}", ['X-Pulse-Token' => 'old-token'])
            ->assertNotFound();
        $this->getJson("/api/v1/pulse/responses/{$response->id}", ['X-Pulse-Token' => $updated])
            ->assertOk();
    });
});

describe('admin dashboard', function () {
    it('lists responses for a survey admin', function () {
        actingAsRole(RoleName::Admin);
        $newest = PulseResponse::factory()->create(['name' => 'Newest']);
        PulseResponse::factory()->create(['name' => 'Older', 'updated_at' => now()->subDay()]);

        $this->getJson('/api/v1/pulse/admin/responses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonMissingPath('data.0.edit_token_hash');
    });

    it('hides the dashboard from people without the permission', function () {
        actingAsRole(RoleName::Reporter);

        $this->getJson('/api/v1/pulse/admin/responses')->assertForbidden();
    });

    it('does not accept a personal token, even for an admin', function () {
        Sanctum::actingAs(userWithRole(RoleName::Admin), ['mcp:read']);

        $this->getJson('/api/v1/pulse/admin/responses')->assertForbidden();
    });
});

describe('notifications', function () {
    it('emails admins when a response arrives, and only then', function () {
        Notification::fake();

        $admin = userWithRole(RoleName::Admin);
        $reporter = userWithRole(RoleName::Reporter);

        $token = $this->postJson('/api/v1/pulse/responses', pulsePayload())
            ->assertCreated()
            ->json('data.edit_token');

        Notification::assertSentTo($admin, PulseResponseSubmitted::class);
        Notification::assertNotSentTo($reporter, PulseResponseSubmitted::class);
        Notification::assertSentTimes(PulseResponseSubmitted::class, 1);

        $response = PulseResponse::query()->firstOrFail();

        $this->putJson(
            "/api/v1/pulse/responses/{$response->id}",
            pulsePayload(['name' => 'Ada B.']),
            ['X-Pulse-Token' => $token],
        )->assertOk();

        // Editing is not a new response, so it must not send another alert.
        Notification::assertSentTimes(PulseResponseSubmitted::class, 1);
    });

    it('skips admins who have been deactivated', function () {
        Notification::fake();

        userWithRole(RoleName::Admin, ['is_active' => false]);

        $this->postJson('/api/v1/pulse/responses', pulsePayload())->assertCreated();

        Notification::assertNothingSent();
    });
});

it('names the dashboard link in the alert email', function () {
    $response = PulseResponse::factory()->create();

    $url = app(PulseSurvey::class)->dashboardUrl($response);

    expect($url)->toContain('/admin/pulse?response='.$response->id);
});
