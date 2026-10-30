<?php

use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\TimeEntry;

it('merges status changes, assignments, updates and time entries, oldest first', function () {
    $this->freezeTime();
    $noc = actingAsRole(RoleName::Noc);
    $incident = Incident::factory()->create();

    $this->travel(1)->minutes();
    $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge")->assertOk();
    $this->travel(1)->minutes();
    IncidentUpdate::factory()->for($incident)->create(['user_id' => $noc->id, 'body' => 'Looking at the queue']);
    $this->travel(1)->minutes();
    TimeEntry::factory()->for($incident)->create(['user_id' => $noc->id, 'minutes' => 15]);

    $feed = $this->getJson("/api/v1/incidents/{$incident->id}/timeline")->assertOk()->json('data');

    expect(array_column($feed, 'type'))->toBe(['status_change', 'status_change', 'assignment', 'update', 'time_entry'])
        ->and($feed[1]['data'])->toBe(['from' => 'reported', 'to' => 'acknowledged', 'note' => null])
        ->and($feed[1]['actor'])->toBe(['id' => $noc->id, 'name' => $noc->name])
        ->and($feed[3]['data']['body'])->toBe('Looking at the queue')
        ->and($feed[4]['data']['minutes'])->toBe(15);
});

it('returns the seconds spent in each status, including the current one', function () {
    $this->freezeTime();
    actingAsRole(RoleName::Noc);
    $incident = Incident::factory()->create();

    $this->travel(5)->minutes();
    $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge")->assertOk();
    $this->travel(2)->minutes();

    $this->getJson("/api/v1/incidents/{$incident->id}/history")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.seconds_in_previous', 300)
        ->assertJsonPath('seconds_in_status', ['reported' => 300, 'acknowledged' => 120]);
});

it('stops counting once the incident is closed', function () {
    actingAsRole(RoleName::Noc);
    $incident = Incident::factory()->inStatus(IncidentStatus::Closed)->create();

    $this->getJson("/api/v1/incidents/{$incident->id}/history")
        ->assertOk()
        ->assertJsonPath('seconds_in_status', []);
});

it('returns 404 for an incident the user cannot see', function () {
    actingAsRole(RoleName::Reporter);
    $incident = Incident::factory()->create();

    $this->getJson("/api/v1/incidents/{$incident->id}/timeline")->assertNotFound();
});
