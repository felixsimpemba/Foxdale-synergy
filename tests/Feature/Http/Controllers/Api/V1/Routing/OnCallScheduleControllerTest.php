<?php

use App\Enums\RoleName;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\Team;
use App\Models\User;

/**
 * @return array{0: OnCallSchedule, 1: User, 2: User}
 */
function scheduleWithTwoMembers(): array
{
    $team = Team::factory()->create();
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $team->addMember($alice);
    $team->addMember($bob);

    return [OnCallSchedule::factory()->for($team)->create(), $alice, $bob];
}

describe('schedules', function () {
    it('creates a schedule', function () {
        actingAsRole(RoleName::IncidentManager);
        $team = Team::factory()->create();

        $this->postJson('/api/v1/on-call-schedules', [
            'team_id' => $team->id, 'name' => 'Payments primary', 'rotation_type' => 'weekly', 'timezone' => 'Africa/Lusaka',
        ])->assertCreated()
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.timezone', 'Africa/Lusaka');
    });

    it('shows current and upcoming shifts to any user', function () {
        actingAsRole(RoleName::Reporter);
        [$schedule, $alice] = scheduleWithTwoMembers();
        OnCallShift::factory()->for($schedule, 'schedule')->create(['user_id' => $alice->id]);
        OnCallShift::factory()->for($schedule, 'schedule')->create(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(2)]);

        $this->getJson("/api/v1/on-call-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.shifts')
            ->assertJsonPath('data.shifts.0.user.name', 'Alice');
    });

    it('returns 403 when NOC creates a schedule', function () {
        actingAsRole(RoleName::Noc);

        $this->postJson('/api/v1/on-call-schedules', ['team_id' => Team::factory()->create()->id, 'name' => 'x', 'rotation_type' => 'weekly'])
            ->assertForbidden();
    });
});

describe('shifts', function () {
    it('adds a week of shifts in one request', function () {
        actingAsRole(RoleName::IncidentManager);
        [$schedule, $alice, $bob] = scheduleWithTwoMembers();

        $this->postJson("/api/v1/on-call-schedules/{$schedule->id}/shifts", ['shifts' => [
            ['user_id' => $alice->id, 'starts_at' => '2026-11-02T08:00:00Z', 'ends_at' => '2026-11-05T08:00:00Z'],
            ['user_id' => $bob->id, 'starts_at' => '2026-11-05T08:00:00Z', 'ends_at' => '2026-11-09T08:00:00Z'],
        ]])->assertCreated()->assertJsonCount(2, 'data');
    });

    it('rejects overlapping regular shifts', function () {
        actingAsRole(RoleName::IncidentManager);
        [$schedule, $alice, $bob] = scheduleWithTwoMembers();
        OnCallShift::factory()->for($schedule, 'schedule')->create([
            'user_id' => $alice->id, 'starts_at' => '2026-11-02 08:00:00', 'ends_at' => '2026-11-05 08:00:00',
        ]);

        $this->postJson("/api/v1/on-call-schedules/{$schedule->id}/shifts", ['shifts' => [
            ['user_id' => $bob->id, 'starts_at' => '2026-11-04T08:00:00Z', 'ends_at' => '2026-11-06T08:00:00Z'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('shifts.0.starts_at');
    });

    it('allows an override on top of a regular shift', function () {
        actingAsRole(RoleName::IncidentManager);
        [$schedule, $alice, $bob] = scheduleWithTwoMembers();
        OnCallShift::factory()->for($schedule, 'schedule')->create(['user_id' => $alice->id]);

        $this->postJson("/api/v1/on-call-schedules/{$schedule->id}/shifts", ['shifts' => [
            ['user_id' => $bob->id, 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'is_override' => true],
        ]])->assertCreated();

        $this->getJson("/api/v1/on-call/now?team_id={$schedule->team_id}")
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Bob')
            ->assertJsonPath('data.shift.is_override', true);
    });

    it('rejects users who are not on the team, and shifts that end before they start', function () {
        actingAsRole(RoleName::IncidentManager);
        [$schedule] = scheduleWithTwoMembers();

        $this->postJson("/api/v1/on-call-schedules/{$schedule->id}/shifts", ['shifts' => [
            ['user_id' => User::factory()->create()->id, 'starts_at' => '2026-11-02T08:00:00Z', 'ends_at' => '2026-11-03T08:00:00Z'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['shifts.0.user_id' => 'The user is not a member of this schedule\'s team.']);

        $this->postJson("/api/v1/on-call-schedules/{$schedule->id}/shifts", ['shifts' => [
            ['user_id' => User::factory()->create()->id, 'starts_at' => '2026-11-03T08:00:00Z', 'ends_at' => '2026-11-02T08:00:00Z'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('shifts.0.ends_at');
    });

    it('deletes a shift only through its own schedule', function () {
        actingAsRole(RoleName::IncidentManager);
        [$schedule] = scheduleWithTwoMembers();
        $shift = OnCallShift::factory()->for($schedule, 'schedule')->create();
        $otherSchedule = OnCallSchedule::factory()->create();

        $this->deleteJson("/api/v1/on-call-schedules/{$otherSchedule->id}/shifts/{$shift->id}")->assertNotFound();
        $this->deleteJson("/api/v1/on-call-schedules/{$schedule->id}/shifts/{$shift->id}")->assertNoContent();

        $this->assertModelMissing($shift);
    });
});

it('returns nobody on call when no shift covers now', function () {
    actingAsRole(RoleName::Reporter);
    $team = Team::factory()->create();

    $this->getJson("/api/v1/on-call/now?team_id={$team->id}")
        ->assertOk()
        ->assertJsonPath('data.user', null)
        ->assertJsonPath('data.shift', null);
});
