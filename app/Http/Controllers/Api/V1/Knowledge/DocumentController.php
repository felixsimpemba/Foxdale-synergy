<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Enums\ScanStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentVersionRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Incident;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $documents) {}

    public function index(Incident $incident): AnonymousResourceCollection
    {
        Gate::authorize('view', $incident);

        return DocumentResource::collection(
            $incident->documents()->with('uploader')->orderByDesc('created_at')->orderByDesc('id')->get(),
        );
    }

    /**
     * Multipart upload: file, type, title, description.
     */
    public function store(StoreDocumentRequest $request, Incident $incident): JsonResponse
    {
        Gate::authorize('uploadDocument', $incident);

        $document = $this->documents->upload($incident, $request->user(), $request->file('file'), $request->safe()->except('file'));

        return (new DocumentResource($document->load('uploader', 'versions')))->response()->setStatusCode(201);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load('uploader', 'versions'));
    }

    public function storeVersion(StoreDocumentVersionRequest $request, Document $document): JsonResponse
    {
        Gate::authorize('addVersion', $document);

        $updated = $this->documents->addVersion($document, $request->user(), $request->file('file'));

        return (new DocumentResource($updated->load('uploader', 'versions')))->response()->setStatusCode(201);
    }

    /**
     * A signed URL for the current version (or ?version=n), valid for 5 minutes.
     */
    public function download(Request $request, Document $document): JsonResponse
    {
        Gate::authorize('view', $document);

        $request->validate(['version' => ['nullable', 'integer', 'min:1']]);
        $version = $this->documents->version($document, $request->filled('version') ? $request->integer('version') : null);
        $this->ensureNotQuarantined($version);

        $link = $this->documents->downloadLink($document, $request->user(), $version->version);

        return response()->json(['data' => [
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601ZuluString(),
            'version' => $link['version'],
        ]]);
    }

    /**
     * The file itself. Reached only through a signed URL from download().
     */
    public function file(Request $request, Document $document): StreamedResponse
    {
        $version = $this->documents->version($document, $request->integer('version') ?: null);
        $this->ensureNotQuarantined($version);

        // Previews (inline=1 is part of the signature) show PNG and JPEG in the page; every
        // other type is always sent as a download so the browser never renders it.
        if ($request->boolean('inline') && in_array($version->mime, ['image/png', 'image/jpeg'], true)) {
            return Storage::disk($document->disk)->response($version->path, $version->original_name, [
                'Content-Type' => $version->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=1800',
            ]);
        }

        return Storage::disk($document->disk)->download($version->path, $version->original_name, [
            'Content-Type' => $version->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Soft delete; the files are kept for the audit trail.
     */
    public function destroy(Document $document): Response
    {
        Gate::authorize('delete', $document);

        $document->delete();

        return response()->noContent();
    }

    private function ensureNotQuarantined(DocumentVersion $version): void
    {
        if ($version->scan_status === ScanStatus::Quarantined) {
            throw new ApiException('This file failed the virus scan and cannot be downloaded.', 'document_quarantined', 403);
        }
    }
}
