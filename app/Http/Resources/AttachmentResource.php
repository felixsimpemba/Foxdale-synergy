<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file attached to a comment. preview_url shows PNG and JPEG inline and is null for other
 * types; downloads still go through GET /documents/{id}/download, which is audited.
 *
 * @mixin Document
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'scan_status' => $this->scan_status,
            'is_image' => $this->resource->isPreviewableImage(),
            'preview_url' => app(DocumentService::class)->previewUrl($this->resource),
        ];
    }
}
