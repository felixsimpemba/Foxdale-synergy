<?php

namespace App\Models;

use App\Enums\FixType;
use App\Models\Concerns\Auditable;
use Database\Factories\IncidentResolutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Structured fix documentation. Summary and fix type are needed to resolve an incident;
 * a verification note as well before a SEV3 or SEV4 incident can be closed.
 *
 * @property FixType $fix_type
 * @property Carbon|null $deployed_at
 * @property bool $is_published
 * @property Carbon|null $published_at
 */
#[Fillable([
    'incident_id', 'summary', 'workaround', 'permanent_fix', 'fix_type', 'steps', 'linked_prs',
    'deployed_at', 'verification', 'follow_up_needed', 'updated_by',
])]
class IncidentResolution extends Model
{
    /** @use HasFactory<IncidentResolutionFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fix_type' => FixType::class,
            'steps' => 'array',
            'linked_prs' => 'array',
            'deployed_at' => 'datetime',
            'follow_up_needed' => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
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
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Complete enough to close a SEV3 or SEV4 incident.
     */
    public function isComplete(): bool
    {
        return filled($this->summary) && filled($this->verification);
    }
}
