<?php

use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\InAppAlert;
use App\Notifications\IncidentNotice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $data
 */
function postMentionComment(Incident $incident, array $data): TestResponse
{
    return test()->postJson("/api/v1/incidents/{$incident->id}/updates", $data);
}

it('stores a mention, shows it on the timeline and tells the person', function () {
    $author = actingAsRole(RoleName::Noc);
    $ada = userWithRole(RoleName::Responder);
    $incident = Incident::factory()->assignedTo($ada)->create();

    postMentionComment($incident, ['body' => "@{$ada->name} the queue is backing up, can you look?"])
        ->assertCreated()
        ->assertJsonPath('data.mentions.0.id', $ada->id)
        ->assertJsonPath('data.mentions.0.name', $ada->name);

    Notification::assertSentTo($ada, InAppAlert::class);
    Notification::assertSentTo($ada, IncidentNotice::class, fn (IncidentNotice $mail) => str_contains($mail->subjectLine, $incident->ref));
    Notification::assertNotSentTo($author, InAppAlert::class);

    $update = collect($this->getJson("/api/v1/incidents/{$incident->id}/timeline")->json('data'))
        ->firstWhere('type', 'update');

    expect($update['data']['mentions'])->toBe([['id' => $ada->id, 'name' => $ada->name]]);
});

it('does not mention somebody who cannot open the incident', function () {
    actingAsRole(RoleName::Noc);
    $outsider = User::factory()->create();
    $incident = Incident::factory()->create();

    postMentionComment($incident, ['body' => "@{$outsider->name} can you look?"])
        ->assertCreated()
        ->assertJsonPath('data.mentions', []);

    Notification::assertNotSentTo($outsider, InAppAlert::class);
    expect($incident->updates()->first()->mentions()->count())->toBe(0);
});

it('mentions the longest matching name first', function () {
    $author = actingAsRole(RoleName::Noc);
    $ada = userWithRole(RoleName::Responder, ['name' => 'Ada Phiri']);
    $other = userWithRole(RoleName::Responder, ['name' => 'Ada']);
    $incident = Incident::factory()->assignedTo($author)->create();
    $incident->watchers()->attach([$ada->id, $other->id]);

    $mentions = collect(postMentionComment($incident, ['body' => 'Thanks @Ada Phiri, and @Ada too.'])->json('data.mentions'))
        ->pluck('id');

    expect($mentions)->toContain($ada->id, $other->id)->toHaveCount(2);
});

it('tells only newly mentioned people when a comment is edited', function () {
    $author = actingAsRole(RoleName::Responder);
    $ada = userWithRole(RoleName::Responder);
    $incident = Incident::factory()->create(['reporter_id' => $author->id]);
    $incident->watchers()->attach($ada);
    $updateId = postMentionComment($incident, ['body' => 'Nothing to report yet'])->json('data.id');

    Notification::assertNotSentTo($ada, InAppAlert::class);

    $this->putJson("/api/v1/incident-updates/{$updateId}", ['body' => "Actually @{$ada->name} please check the logs"])
        ->assertOk()
        ->assertJsonPath('data.mentions.0.id', $ada->id);

    Notification::assertSentToTimes($ada, InAppAlert::class, 1);

    // Removing the mention again does not notify anyone.
    $this->putJson("/api/v1/incident-updates/{$updateId}", ['body' => 'Never mind'])->assertOk()->assertJsonPath('data.mentions', []);
    Notification::assertSentToTimes($ada, InAppAlert::class, 1);
});

describe('who can be mentioned', function () {
    it('lists the people who can see the incident, and nobody else', function () {
        $noc = actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $watcher = userWithRole(RoleName::Responder);
        $incident->watchers()->attach($watcher);
        $outsider = User::factory()->create();

        $ids = collect($this->getJson("/api/v1/incidents/{$incident->id}/mentionable")->assertOk()->json('data'))
            ->pluck('id');

        expect($ids)->toContain($noc->id, $watcher->id)
            ->not->toContain($outsider->id);
    });

    it('hides the list on an incident the user cannot see', function () {
        actingAsRole(RoleName::Responder);
        $incident = Incident::factory()->create();

        $this->getJson("/api/v1/incidents/{$incident->id}/mentionable")->assertNotFound();
    });
});
