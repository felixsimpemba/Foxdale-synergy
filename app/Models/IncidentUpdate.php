<?php

namespace App\Models;

use App\Enums\IncidentUpdateType;
use App\Enums\UpdateVisibility;
use Database\Factories\IncidentUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A timeline entry posted on an incident: a comment, a status update for stakeholders,
 * a system note (severity change, monitoring occurrence) or an accepted AI draft.
 *
 * Comments form threads one level deep: a reply's parent_id is the top-level comment. Files
 * attached to an update are documents with incident_update_id set.
 *
 * @property IncidentUpdateType $type
 * @property UpdateVisibility $visibility
 * @property int|null $parent_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['incident_id', 'parent_id', 'user_id', 'type', 'body', 'visibility', 'notified_channels'])]
class IncidentUpdate extends Model
{
    /** @use HasFactory<IncidentUpdateFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IncidentUpdateType::class,
            'visibility' => UpdateVisibility::class,
            'notified_channels' => 'array',
            'edited_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<IncidentUpdate, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Replies, oldest first. Includes deleted ones only when loaded withTrashed.
     *
     * @return HasMany<IncidentUpdate, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Document::class)->orderBy('id');
    }

    /**
     * People write comments and status updates; system notes and AI drafts are not theirs to
     * edit or reply to.
     */
    public function isConversation(): bool
    {
        return in_array($this->type, [IncidentUpdateType::Comment, IncidentUpdateType::StatusUpdate], true);
    }
}
