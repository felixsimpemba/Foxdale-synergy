<?php

use App\Enums\RoleName;
use App\Enums\ScanStatus;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Incident;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('local');
});

function uploadTo(Incident $incident, ?UploadedFile $file = null, array $extra = []): TestResponse
{
    return test()->post("/api/v1/incidents/{$incident->id}/documents", [
        'file' => $file ?? UploadedFile::fake()->createWithContent('queue-depth.log', "08:55 consumer disconnected\n"),
        'type' => 'evidence',
        'title' => 'Consumer log',
        ...$extra,
    ], ['Accept' => 'application/json']);
}

describe('upload', function () {
    it('stores the file privately under the incident reference with its checksum', function () {
        $reporter = actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create(['reporter_id' => $reporter->id]);
        $content = "08:55 consumer disconnected\n";

        $response = uploadTo($incident, UploadedFile::fake()->createWithContent('Queue Depth.log', $content));

        $document = Document::sole();
        $response->assertCreated()
            ->assertJsonPath('data.title', 'Consumer log')
            ->assertJsonPath('data.original_name', 'Queue Depth.log')
            ->assertJsonPath('data.sha256', hash('sha256', $content))
            ->assertJsonPath('data.current_version', 1)
            ->assertJsonPath('data.scan_status', 'not_scanned')
            ->assertJsonCount(1, 'data.versions');

        expect($document->path)->toStartWith("incidents/{$incident->fresh()->ref}/")->toEndWith('/queue-depth.log');
        Storage::disk('local')->assertExists($document->path);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'document.created', 'entity_id' => $document->id]);
    });

    it('rejects a file type that is not allowed', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();

        uploadTo($incident, UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        expect(Document::count())->toBe(0);
    });

    it('rejects files above the configured size limit', function () {
        actingAsRole(RoleName::Noc);
        app(SettingsService::class)->update(['uploads.max_kb' => 100]);
        $incident = Incident::factory()->create();

        uploadTo($incident, UploadedFile::fake()->create('dump.zip', 200, 'application/zip'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    });

    it('returns 404 when uploading to an incident the user cannot see', function () {
        actingAsRole(RoleName::Reporter);
        $incident = Incident::factory()->create();

        uploadTo($incident)->assertNotFound();
    });
});

describe('versions and downloads', function () {
    it('keeps earlier versions downloadable after a new version', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $documentId = uploadTo($incident, UploadedFile::fake()->createWithContent('config.txt', 'timeout=5'))->json('data.id');

        $this->post("/api/v1/documents/{$documentId}/versions", [
            'file' => UploadedFile::fake()->createWithContent('config.txt', 'timeout=30'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.current_version', 2)
            ->assertJsonCount(2, 'data.versions');

        $current = $this->getJson("/api/v1/documents/{$documentId}/download")->assertOk()->json('data');
        $first = $this->getJson("/api/v1/documents/{$documentId}/download?version=1")->assertOk()->json('data');

        expect($current['version'])->toBe(2)
            ->and($this->get($current['url'])->assertOk()->streamedContent())->toBe('timeout=30')
            ->and($this->get($first['url'])->assertOk()->streamedContent())->toBe('timeout=5');
    });

    it('issues a link that expires after 5 minutes and records the download', function () {
        $user = actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        $documentId = uploadTo($incident)->json('data.id');

        $url = $this->getJson("/api/v1/documents/{$documentId}/download")->assertOk()->json('data.url');

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'document.downloaded', 'entity_id' => $documentId, 'user_id' => $user->id]);

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('rejects a link whose signature was tampered with', function () {
        actingAsRole(RoleName::Noc);
        $documentId = uploadTo(Incident::factory()->create())->json('data.id');
        $url = $this->getJson("/api/v1/documents/{$documentId}/download")->json('data.url');

        $this->get(str_replace('version=1', 'version=2', $url))->assertForbidden();
    });

    it('returns 403 document_quarantined for a file that failed the virus scan', function () {
        actingAsRole(RoleName::Noc);
        $documentId = uploadTo(Incident::factory()->create())->json('data.id');
        Document::find($documentId)->versions()->update(['scan_status' => ScanStatus::Quarantined]);

        $this->getJson("/api/v1/documents/{$documentId}/download")
            ->assertForbidden()
            ->assertJsonPath('code', 'document_quarantined');
    });

    it('lists documents on the incident and includes them in the detail view', function () {
        actingAsRole(RoleName::Noc);
        $incident = Incident::factory()->create();
        uploadTo($incident);

        $this->getJson("/api/v1/incidents/{$incident->id}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/incidents/{$incident->id}?include=documents")->assertOk()->assertJsonCount(1, 'data.documents');
    });
});

describe('delete', function () {
    it('lets the uploader delete their own document', function () {
        actingAsRole(RoleName::Noc);
        $documentId = uploadTo(Incident::factory()->create())->json('data.id');

        $this->deleteJson("/api/v1/documents/{$documentId}")->assertNoContent();

        $this->assertSoftDeleted(Document::withTrashed()->find($documentId));
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'document.deleted', 'entity_id' => $documentId]);
    });

    it('returns 403 when someone else deletes it without document.delete', function () {
        actingAsRole(RoleName::Noc);
        $document = Document::factory()->create();

        $this->deleteJson("/api/v1/documents/{$document->id}")->assertForbidden();
    });

    it('lets an incident manager delete any document', function () {
        actingAsRole(RoleName::IncidentManager);
        $document = Document::factory()->create();

        $this->deleteJson("/api/v1/documents/{$document->id}")->assertNoContent();
    });

    it('stops serving a deleted document even with a valid link', function () {
        actingAsRole(RoleName::Noc);
        $documentId = uploadTo(Incident::factory()->create())->json('data.id');
        $url = $this->getJson("/api/v1/documents/{$documentId}/download")->json('data.url');

        $this->deleteJson("/api/v1/documents/{$documentId}")->assertNoContent();

        $this->get($url)->assertNotFound();
    });
});
