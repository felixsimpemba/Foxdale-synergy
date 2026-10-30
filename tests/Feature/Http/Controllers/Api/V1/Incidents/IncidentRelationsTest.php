<?php

use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\IncidentResolution;

describe('watchers', function () {
    it('subscribes and unsubscribes the current user', function () {
        $user = actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/watchers")->assertOk()->assertJsonPath('watching', true);
        $this->postJson("/api/v1/incidents/{$incident->id}/watchers")->assertOk();
        expect($incident->watchers()->pluck('users.id')->all())->toBe([$user->id]);

        $this->deleteJson("/api/v1/incidents/{$incident->id}/watchers")->assertOk()->assertJsonPath('watching', false);
        expect($incident->watchers()->count())->toBe(0);
    });

    it('returns 404 for an incident the user cannot see', function () {
        actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/watchers")->assertNotFound();
    });
});

describe('links', function () {
    it('links two incidents once', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $cause = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/links", ['related_incident_id' => $cause->id, 'type' => 'caused_by'])
            ->assertCreated()
            ->assertJsonPath('data.related_incident.ref', $cause->fresh()->ref);

        $this->postJson("/api/v1/incidents/{$incident->id}/links", ['related_incident_id' => $cause->id, 'type' => 'caused_by'])
            ->assertOk();

        expect($incident->links()->count())->toBe(1);
    });

    it('rejects a link to itself', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$incident->id}/links", ['related_incident_id' => $incident->id, 'type' => 'related'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('related_incident_id');
    });

    it('rejects a link to an incident the user cannot see', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $own = Incident::factory()->create(['reporter_id' => $reporter->id]);
        $hidden = Incident::factory()->create();

        $this->postJson("/api/v1/incidents/{$own->id}/links", ['related_incident_id' => $hidden->id, 'type' => 'related'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.related_incident_id.0', 'The selected related incident id is invalid.');
    });
});

describe('resolution', function () {
    it('creates and returns the structured fix documentation', function () {
        $responder = actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->assignedTo($responder)->create();

        $this->putJson("/api/v1/incidents/{$incident->id}/resolution", [
            'summary' => 'Callback queue was stuck after a RabbitMQ node restart',
            'workaround' => 'Restarted the consumer service',
            'permanent_fix' => 'Consumer reconnect with backoff',
            'fix_type' => 'code_change',
            'steps' => ['Checked queue depth', 'Restarted the consumer'],
            'linked_prs' => [['url' => 'https://git.example.com/org/repo/pull/482', 'title' => 'Reconnect with backoff']],
            'deployed_at' => '2026-10-28T10:30:00Z',
            'verification' => 'Deposits succeeded for 30 minutes',
            'follow_up_needed' => true,
        ])->assertCreated()
            ->assertJsonPath('data.fix_type', 'code_change')
            ->assertJsonPath('data.steps.1', 'Restarted the consumer')
            ->assertJsonPath('data.is_complete', true)
            ->assertJsonPath('data.updated_by', $responder->id);

        $this->getJson("/api/v1/incidents/{$incident->id}/resolution")
            ->assertOk()
            ->assertJsonPath('data.deployed_at', '2026-10-28T10:30:00Z');
    });

    it('updates the existing resolution instead of adding a second one', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        IncidentResolution::factory()->for($incident)->create();

        $this->putJson("/api/v1/incidents/{$incident->id}/resolution", ['summary' => 'Updated', 'fix_type' => 'rollback'])->assertOk();

        expect(IncidentResolution::sole()->summary)->toBe('Updated');
    });

    it('rejects an unknown fix type and a non-http PR link', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->putJson("/api/v1/incidents/{$incident->id}/resolution", [
            'summary' => 'x', 'fix_type' => 'prayer', 'linked_prs' => [['url' => 'ftp://example.com']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['fix_type', 'linked_prs.0.url']);
    });

    it('returns 404 when no resolution has been written', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $this->getJson("/api/v1/incidents/{$incident->id}/resolution")->assertNotFound();
    });

    it('returns 403 for a reporter', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create(['reporter_id' => $reporter->id]);

        $this->putJson("/api/v1/incidents/{$incident->id}/resolution", ['summary' => 'x', 'fix_type' => 'rollback'])->assertForbidden();
    });
});
