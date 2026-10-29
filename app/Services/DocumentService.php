<?php

namespace App\Services;

use App\Enums\ScanStatus;
use App\Jobs\ScanDocumentVersion;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Incident;
use App\Models\User;
use App\Services\Scanning\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stores incident documents and their versions on the private disk under incidents/{ref}/,
 * records SHA-256 checksums, queues virus scans and issues short-lived download links.
 */
class DocumentService
{
    public function __construct(private VirusScanner $scanner, private AuditLogger $audit) {}

    /** Preview links stay the same for this long, so browsers can cache the images. */
    private const PREVIEW_WINDOW_MINUTES = 30;

    /**
     * @param  array{type: string, title: string, description?: string|null, incident_update_id?: int|null}  $meta
     */
    public function upload(Incident $incident, User $uploader, UploadedFile $file, array $meta): Document
    {
        [$disk, $path] = $this->store($incident, $file);
        $attributes = $this->fileAttributes($file, $path);

        try {
            return DB::transaction(function () use ($incident, $uploader, $meta, $disk, $attributes): Document {
                $document = $incident->documents()->create([
                    'incident_update_id' => $meta['incident_update_id'] ?? null,
                    'uploaded_by' => $uploader->id,
                    'type' => $meta['type'],
                    'title' => $meta['title'],
                    'description' => $meta['description'] ?? null,
                    'disk' => $disk,
                    'current_version' => 1,
                    ...$attributes,
                ]);

                $this->recordVersion($document, $uploader, $attributes, 1);

                return $document;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * Upload a new version of the same document; earlier versions stay downloadable.
     */
    public function addVersion(Document $document, User $uploader, UploadedFile $file): Document
    {
        [$disk, $path] = $this->store($document->incident, $file, $document->disk);
        $attributes = $this->fileAttributes($file, $path);

        try {
            return DB::transaction(function () use ($document, $uploader, $attributes): Document {
                $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
                $version = $locked->current_version + 1;

                $locked->update(['current_version' => $version, ...$attributes]);
                $this->recordVersion($locked, $uploader, $attributes, $version);

                return $locked;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * A signed link to the file, valid for a few minutes. Creating it is the audited download.
     *
     * @return array{url: string, expires_at: Carbon, version: int}
     */
    public function downloadLink(Document $document, User $user, ?int $version = null): array
    {
        $resolved = $this->version($document, $version);
        $expiresAt = now()->addMinutes(config('incidents.uploads.download_link_minutes'));

        $this->audit->record('document.downloaded', $document, ['version' => $resolved->version], actor: $user);

        return [
            'url' => URL::temporarySignedRoute('v1.documents.file', $expiresAt, [
                'document' => $document->id,
                'version' => $resolved->version,
            ]),
            'expires_at' => $expiresAt,
            'version' => $resolved->version,
        ];
    }

    /**
     * A signed link that shows a PNG or JPEG inline, for thumbnails in comments. Viewing a
     * preview is not recorded as a download. The expiry is rounded to a fixed window, so the
     * same image keeps the same URL across timeline refreshes and comes from the browser
     * cache. Null for files that are not previewable images.
     */
    public function previewUrl(Document $document): ?string
    {
        if (! $document->isPreviewableImage()) {
            return null;
        }

        $window = self::PREVIEW_WINDOW_MINUTES * 60;
        $expiresAt = Carbon::createFromTimestamp((intdiv(now()->timestamp, $window) + 2) * $window);

        return URL::temporarySignedRoute('v1.documents.file', $expiresAt, [
            'document' => $document->id,
            'version' => $document->current_version,
            'inline' => 1,
        ]);
    }

    public function version(Document $document, ?int $version = null): DocumentVersion
    {
        return $document->versions()->where('version', $version ?? $document->current_version)->firstOrFail();
    }

    /**
     * @return array{0: string, 1: string} disk and path
     */
    private function store(Incident $incident, UploadedFile $file, ?string $disk = null): array
    {
        $disk ??= config('incidents.uploads.disk');
        $extension = Str::lower($file->getClientOriginalExtension());
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';

        $path = Storage::disk($disk)->putFileAs("incidents/{$incident->ref}/".Str::uuid(), $file, "{$name}.{$extension}");

        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        return [$disk, $path];
    }

    /**
     * @return array{path: string, original_name: string, mime: string, size: int, sha256: string, scan_status: ScanStatus}
     */
    private function fileAttributes(UploadedFile $file, string $path): array
    {
        return [
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'scan_status' => $this->scanner->isEnabled() ? ScanStatus::Pending : ScanStatus::NotScanned,
        ];
    }

    /**
     * @param  array{path: string, original_name: string, mime: string, size: int, sha256: string, scan_status: ScanStatus}  $attributes
     */
    private function recordVersion(Document $document, User $uploader, array $attributes, int $version): void
    {
        $record = $document->versions()->create([
            'version' => $version,
            'uploaded_by' => $uploader->id,
            ...$attributes,
        ]);

        if ($this->scanner->isEnabled()) {
            ScanDocumentVersion::dispatch($record)->afterCommit();
        }
    }
}
