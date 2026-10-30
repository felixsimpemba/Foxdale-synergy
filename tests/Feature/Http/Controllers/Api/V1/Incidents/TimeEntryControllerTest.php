<?php

use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\TimeEntry;

describe('manual entries', function () {
    it('logs work time and includes it in work_minutes', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/time-entries", ['minutes' => 45, 'note' => 'Checked queue depth'])
            ->assertCreated()
            ->assertJsonPath('data.minutes', 45)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.worked_on', today()->toDateString());

        $this->getJson("/api/v1/incidents/{$incident->id}")->assertJsonPath('data.timings.work_minutes', 45);
    });

    it('rejects zero minutes, more than a day, and future dates', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/time-entries", ['minutes' => 0, 'worked_on' => now()->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['minutes', 'worked_on']);

        $this->postJson("/api/v1/incidents/{$incident->id}/time-entries", ['minutes' => 1441])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('minutes');
    });

    it('returns 403 for a reporter on their own incident', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create(['reporter_id' => $reporter->id]);

        $this->postJson("/api/v1/incidents/{$incident->id}/time-entries", ['minutes' => 10])->assertForbidden();
    });

    it('lets users edit and delete only their own entries', function () {
        $responder = actingAsRole(RoleName::Noc);
        $own = TimeEntry::factory()->create(['user_id' => $responder->id, 'minutes' => 10]);
        $someoneElses = TimeEntry::factory()->create();

        $this->putJson("/api/v1/time-entries/{$own->id}", ['minutes' => 25])->assertOk()->assertJsonPath('data.minutes', 25);
        $this->putJson("/api/v1/time-entries/{$someoneElses->id}", ['minutes' => 25])->assertNotFound();
        $this->deleteJson("/api/v1/time-entries/{$someoneElses->id}")->assertNotFound();
        $this->deleteJson("/api/v1/time-entries/{$own->id}")->assertNoContent();

        $this->assertModelMissing($own);
        $this->assertModelExists($someoneElses);
    });
});

describe('timer', function () {
    it('records the elapsed minutes, rounded up', function () {
        $this->freezeTime();
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/timer/start")
            ->assertCreated()
            ->assertJsonPath('data.is_running', true);

        $this->travel(12)->minutes();
        $this->travel(30)->seconds();

        $this->postJson("/api/v1/incidents/{$incident->id}/timer/stop")
            ->assertOk()
            ->assertJsonPath('data.is_running', false)
            ->assertJsonPath('data.minutes', 13)
            ->assertJsonPath('data.source', 'timer');
    });

    it('returns 422 timer_running when a timer is already running', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/timer/start")->assertCreated();
        $this->postJson("/api/v1/incidents/{$incident->id}/timer/start")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'timer_running');
    });

    it('returns 422 no_running_timer when stopping without a timer', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/timer/stop")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'no_running_timer');
    });

    it('returns 422 incident_not_open on a resolved incident', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->inStatus(IncidentStatus::Resolved)->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/timer/start")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'incident_not_open');
    });
});
