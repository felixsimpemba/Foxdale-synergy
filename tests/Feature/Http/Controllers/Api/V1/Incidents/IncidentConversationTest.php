<?php

use App\Enums\IncidentUpdateType;
use App\Enums\PermissionKey;
use App\Enums\RoleName;
use App\Enums\UpdateVisibility;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $data
 */
function postUpdate(Incident $incident, array $data): TestResponse
{
    return test()->post("/api/v1/incidents/{$incident->id}/updates", $data, ['Accept' => 'application/json']);
}

function screenshot(string $name = 'error.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 40, 30);
}

describe('replies', function () {
    it('adds a reply to the thread of the comment it answers', function () {
        $noc = actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $root = IncidentUpdate::factory()->for($incident)->create();

        $reply = postUpdate($incident, ['body' => 'Seeing the same in Ndola', 'parent_id' => $root->id])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id)
            ->assertJsonPath('data.author.id', $noc->id)
            ->json('data');

        // Answering a reply still joins the top-level comment: threads are one level deep.
        postUpdate($incident, ['body' => 'Fixed now?', 'parent_id' => $reply['id']])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id);
    });

    it('keeps replies as internal comments in an internal thread', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $root = IncidentUpdate::factory()->for($incident)->create(['visibility' => UpdateVisibility::Internal]);

        postUpdate($incident, [
            'body' => 'Customer facing note?',
            'parent_id' => $root->id,
            'visibility' => 'stakeholder',
            'type' => 'status_update',
        ])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'internal')
            ->assertJsonPath('data.type', 'comment');
    });

    it('rejects a reply to a comment on another incident, a deleted comment or a system note', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $elsewhere = IncidentUpdate::factory()->create();
        $deleted = IncidentUpdate::factory()->for($incident)->create();
        $deleted->delete();
        $system = IncidentUpdate::factory()->for($incident)->create(['type' => IncidentUpdateType::System, 'user_id' => null]);

        foreach ([$elsewhere, $deleted, $system] as $parent) {
            postUpdate($incident, ['body' => 'Reply', 'parent_id' => $parent->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('parent_id');
        }
    });
});

describe('attachments', function () {
    it('stores attached files as incident documents linked to the comment', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $response = postUpdate($incident, [
            'body' => 'Error on the payment page',
            'attachments' => [screenshot('payment-error.png'), UploadedFile::fake()->create('trace.pdf', 20, 'application/pdf')],
        ])->assertCreated();

        $attachments = $response->json('data.attachments');
        expect($attachments)->toHaveCount(2)
            ->and($attachments[0]['original_name'])->toBe('payment-error.png')
            ->and($attachments[0]['is_image'])->toBeTrue()
            ->and($attachments[0]['preview_url'])->toContain('inline=1')
            ->and($attachments[1]['is_image'])->toBeFalse()
            ->and($attachments[1]['preview_url'])->toBeNull();

        $documents = Document::orderBy('id')->get();
        expect($documents->pluck('incident_update_id')->unique()->all())->toBe([$response->json('data.id')])
            ->and($documents->pluck('type')->map->value->all())->toBe(['evidence', 'other']);

        // They are ordinary incident documents too.
        $this->getJson("/api/v1/incidents/{$incident->id}/documents")->assertOk()->assertJsonCount(2, 'data');
    });

    it('accepts files without text, but not an empty post', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        postUpdate($incident, ['attachments' => [screenshot()]])->assertCreated()->assertJsonPath('data.body', '');

        postUpdate($incident, [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'Write a message or attach a file.');
    });

    it('rejects a disallowed file type by name and posts nothing', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        $response = postUpdate($incident, [
            'body' => 'See attached',
            'attachments' => [screenshot(), UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')],
        ])->assertUnprocessable();

        expect($response->json('errors')['attachments.1'][0])->toContain('"tool.exe"')
            ->and(IncidentUpdate::count())->toBe(0)
            ->and(Document::count())->toBe(0);
    });

    it('returns 403 when attaching files without document.upload', function () {
        $role = Role::create(['name' => 'commenter', 'guard_name' => 'web']);
        $role->givePermissionTo([PermissionKey::IncidentViewAll->value, PermissionKey::IncidentUpdate->value]);
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);
        $incident = Incident::factory()->create();

        postUpdate($incident, ['body' => 'Text is fine'])->assertCreated();
        postUpdate($incident, ['body' => 'With a file', 'attachments' => [screenshot()]])->assertForbidden();
    });

    it('serves an image preview inline from a link that stays the same between loads', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        postUpdate($incident, ['body' => 'Screenshot', 'attachments' => [screenshot()]])->assertCreated();

        $first = $this->getJson("/api/v1/incidents/{$incident->id}/timeline")->json('data');
        $this->travel(2)->minutes();
        $second = $this->getJson("/api/v1/incidents/{$incident->id}/timeline")->json('data');

        $preview = collect($first)->firstWhere('type', 'update')['data']['attachments'][0]['preview_url'];
        expect(collect($second)->firstWhere('type', 'update')['data']['attachments'][0]['preview_url'])->toBe($preview);

        $this->get($preview)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename=error.png');

        // Previews are not audited as downloads.
        expect(AuditLog::where('action', 'document.downloaded')->count())->toBe(0);
    });

    it('never shows other file types inline, even with inline=1', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $documentId = postUpdate($incident, ['body' => 'Trace', 'attachments' => [UploadedFile::fake()->create('trace.pdf', 20, 'application/pdf')]])
            ->json('data.attachments.0.id');

        $url = URL::temporarySignedRoute('v1.documents.file', now()->addMinutes(5), ['document' => $documentId, 'version' => 1, 'inline' => 1]);

        expect($this->get($url)->assertOk()->headers->get('Content-Disposition'))->toStartWith('attachment;');
    });
});

describe('timeline threads', function () {
    it('shows each thread once with its replies and files, oldest reply first', function () {
        $this->freezeTime();
        $noc = actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $root = postUpdate($incident, ['body' => 'Queue is growing', 'attachments' => [screenshot('queue.png')]])->json('data');
        $this->travel(5)->minutes();
        postUpdate($incident, ['body' => 'Restarting the consumer', 'parent_id' => $root['id']])->assertCreated();
        $this->travel(5)->minutes();
        postUpdate($incident, ['body' => 'Draining now', 'parent_id' => $root['id']])->assertCreated();

        $updates = collect($this->getJson("/api/v1/incidents/{$incident->id}/timeline")->json('data'))
            ->where('type', 'update')
            ->values();

        expect($updates)->toHaveCount(1)
            ->and($updates[0]['data']['body'])->toBe('Queue is growing')
            ->and($updates[0]['data']['attachments'][0]['original_name'])->toBe('queue.png')
            ->and(array_column($updates[0]['data']['replies'], 'body'))->toBe(['Restarting the consumer', 'Draining now'])
            ->and($updates[0]['data']['replies'][0]['actor'])->toBe(['id' => $noc->id, 'name' => $noc->name])
            ->and($updates[0]['data']['last_activity_at'])->toBe(now()->toIso8601ZuluString());
    });

    it('keeps a deleted comment as a placeholder while it has replies, and drops deleted replies', function () {
        $noc = actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $withReplies = IncidentUpdate::factory()->for($incident)->create(['user_id' => $noc->id, 'body' => 'Secret']);
        IncidentUpdate::factory()->for($incident)->create(['parent_id' => $withReplies->id, 'body' => 'Still here']);
        IncidentUpdate::factory()->for($incident)->create(['parent_id' => $withReplies->id, 'body' => 'Gone'])->delete();
        $alone = IncidentUpdate::factory()->for($incident)->create(['user_id' => $noc->id]);
        $withReplies->delete();
        $alone->delete();

        $updates = collect($this->getJson("/api/v1/incidents/{$incident->id}/timeline")->json('data'))
            ->where('type', 'update')
            ->values();

        expect($updates)->toHaveCount(1)
            ->and($updates[0]['actor'])->toBeNull()
            ->and($updates[0]['data']['is_deleted'])->toBeTrue()
            ->and($updates[0]['data']['body'])->toBeNull()
            ->and(array_column($updates[0]['data']['replies'], 'body'))->toBe(['Still here']);
    });
});

describe('editing and deleting', function () {
    it('lets the author edit their comment, marks it edited and audits the change', function () {
        $noc = actingAsRole(RoleName::Noc);
        $update = IncidentUpdate::factory()->create(['user_id' => $noc->id, 'body' => 'Tpyo']);

        $this->putJson("/api/v1/incident-updates/{$update->id}", ['body' => 'Typo'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Typo')
            ->assertJsonPath('data.edited_at', fn (?string $at) => $at !== null);

        $log = AuditLog::firstWhere('action', 'incident_update.edited');
        expect($log->changes)->toMatchArray(['update_id' => $update->id, 'before' => ['body' => 'Tpyo'], 'after' => ['body' => 'Typo']]);
    });

    it('returns 403 for someone else\'s comment or a system note, and 404 on a hidden incident', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $own = Incident::factory()->create(['reporter_id' => $reporter->id]);
        $others = IncidentUpdate::factory()->for($own)->create();
        $system = IncidentUpdate::factory()->for($own)->create(['type' => IncidentUpdateType::System, 'user_id' => $reporter->id]);
        $hidden = IncidentUpdate::factory()->create(['user_id' => $reporter->id]);

        $this->putJson("/api/v1/incident-updates/{$others->id}", ['body' => 'x'])->assertForbidden();
        $this->putJson("/api/v1/incident-updates/{$system->id}", ['body' => 'x'])->assertForbidden();
        $this->putJson("/api/v1/incident-updates/{$hidden->id}", ['body' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/incident-updates/{$others->id}")->assertForbidden();
    });

    it('deletes the author\'s comment together with its files', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $updateId = postUpdate($incident, ['body' => 'Wrong screenshot', 'attachments' => [screenshot()]])->json('data.id');

        $this->deleteJson("/api/v1/incident-updates/{$updateId}")->assertNoContent();

        $this->assertSoftDeleted('incident_updates', ['id' => $updateId]);
        expect(Document::count())->toBe(0)
            ->and(Document::withTrashed()->count())->toBe(1);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'incident_update.deleted']);
    });
});
