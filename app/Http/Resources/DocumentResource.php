<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata only: files are fetched through GET /documents/{id}/download.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incident_id,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'scan_status' => $this->scan_status,
            'current_version' => $this->current_version,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader === null ? null : ['id' => $this->uploader->id, 'name' => $this->uploader->name]),
            'versions' => DocumentVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
