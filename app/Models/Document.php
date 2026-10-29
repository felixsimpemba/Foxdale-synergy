<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\ScanStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file attached to an incident. The columns describe the current version; every version,
 * including the current one, is kept in document_versions and stays downloadable.
 *
 * @property DocumentType $type
 * @property ScanStatus $scan_status
 * @property int $current_version
 * @property int $size
 * @property int|null $incident_update_id
 */
#[Fillable([
    'incident_id', 'incident_update_id', 'uploaded_by', 'type', 'title', 'description', 'disk', 'path', 'original_name',
    'mime', 'size', 'sha256', 'scan_status', 'current_version',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'scan_status' => ScanStatus::class,
            'size' => 'integer',
            'current_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    /**
     * The comment this file was attached to, if any.
     *
     * @return BelongsTo<IncidentUpdate, $this>
     */
    public function incidentUpdate(): BelongsTo
    {
        return $this->belongsTo(IncidentUpdate::class, 'incident_update_id');
    }

    /**
     * PNG and JPEG are shown inline as previews. Other types (including SVG, which can carry
     * scripts) are only ever downloaded.
     */
    public function isPreviewableImage(): bool
    {
        return in_array($this->mime, ['image/png', 'image/jpeg'], true)
            && $this->scan_status !== ScanStatus::Quarantined;
    }
}
