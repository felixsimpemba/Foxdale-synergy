<?php

use App\Enums\RoleName;
use App\Events\UpdatePosted;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use Illuminate\Support\Facades\Event;

it('lets a reporter comment on their own incident', function () {
    $reporter = actingAsRole(RoleName::Reporter);
    $incident = Incident::factory()->create(['reporter_id' => $reporter->id]);

    $this->postJson("/api/v1/incidents/{$incident->id}/updates", ['body' => 'Still failing for me'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'comment')
        ->assertJsonPath('data.visibility', 'internal')
        ->assertJsonPath('data.author.id', $reporter->id);
});

it('asks for chat delivery when notify_channels is true', function () {
    $noc = actingAsRole(RoleName::Noc);
    $incident = Incident::factory()->create();
    Event::fake([UpdatePosted::class]);

    $this->postJson("/api/v1/incidents/{$incident->id}/updates", [
        'body' => 'Mitigation in progress', 'type' => 'status_update', 'visibility' => 'stakeholder', 'notify_channels' => true,
    ])->assertCreated();

    Event::assertDispatched(UpdatePosted::class, fn (UpdatePosted $event) => $event->notifyChannels
        && $event->update->user_id === $noc->id);
});

it('does not accept system or ai updates from clients', function () {
    actingAsRole(RoleName::Noc);
    $incident = Incident::factory()->create();

    $this->postJson("/api/v1/incidents/{$incident->id}/updates", ['body' => 'x', 'type' => 'system'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('lists updates newest first', function () {
    actingAsRole(RoleName::Noc);
    $incident = Incident::factory()->create();
    IncidentUpdate::factory()->for($incident)->create(['body' => 'first', 'created_at' => now()->subMinute()]);
    IncidentUpdate::factory()->for($incident)->create(['body' => 'second']);

    $this->getJson("/api/v1/incidents/{$incident->id}/updates")
        ->assertOk()
        ->assertJsonPath('data.0.body', 'second')
        ->assertJsonPath('data.1.body', 'first');
});

it('returns 404 when commenting on an incident the user cannot see', function () {
    actingAsRole(RoleName::Reporter);
    $incident = Incident::factory()->create();

    $this->postJson("/api/v1/incidents/{$incident->id}/updates", ['body' => 'x'])->assertNotFound();
});
