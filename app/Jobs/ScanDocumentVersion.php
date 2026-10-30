<?php

namespace App\Jobs;

use App\Models\DocumentVersion;
use App\Services\Scanning\VirusScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scans one uploaded version and records the result on the version and, when it is the
 * current version, on the document.
 */
class ScanDocumentVersion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(public DocumentVersion $version) {}

    public function handle(VirusScanner $scanner): void
    {
        $document = $this->version->document()->withTrashed()->firstOrFail();
        $status = $scanner->scan($document->disk, $this->version->path);

        $this->version->update(['scan_status' => $status]);

        if ($document->current_version === $this->version->version) {
            $document->update(['scan_status' => $status]);
        }
    }
}
