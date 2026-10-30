<?php

use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\Team;
use App\Models\User;
use App\Services\OnCallService;

function onCallFor(Team $team): ?User
{
    return app(OnCallService::class)->currentFor($team);
}

it('returns the person whose shift covers now', function () {
    $schedule = OnCallSchedule::factory()->create();
    $shift = OnCallShift::factory()->for($schedule, 'schedule')->create();
    OnCallShift::factory()->for($schedule, 'schedule')->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

    expect(onCallFor($schedule->team)->is($shift->user))->toBeTrue();
});

it('lets an override win over the regular shift', function () {
    $schedule = OnCallSchedule::factory()->create();
    OnCallShift::factory()->for($schedule, 'schedule')->create();
    $override = OnCallShift::factory()->for($schedule, 'schedule')->override()->create(['starts_at' => now()->subHours(3)]);

    expect(onCallFor($schedule->team)->is($override->user))->toBeTrue();
});

it('treats the end of a shift as exclusive', function () {
    $this->freezeTime();
    $schedule = OnCallSchedule::factory()->create();
    OnCallShift::factory()->for($schedule, 'schedule')->create(['starts_at' => now()->subHours(8), 'ends_at' => now()]);

    expect(onCallFor($schedule->team))->toBeNull();
});

it('ignores inactive schedules and deactivated users', function () {
    $team = Team::factory()->create();
    OnCallShift::factory()->for(OnCallSchedule::factory()->for($team)->create(['is_active' => false]), 'schedule')->create();
    OnCallShift::factory()->for(OnCallSchedule::factory()->for($team)->create(), 'schedule')
        ->create(['user_id' => User::factory()->inactive()]);

    expect(onCallFor($team))->toBeNull();
});
