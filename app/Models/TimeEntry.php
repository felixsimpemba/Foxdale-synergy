<?php

namespace App\Models;

use App\Enums\TimeEntrySource;
use App\Models\Concerns\Auditable;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Work time on an incident, logged by hand or by the start/stop timer. A running timer
 * has started_at set and ended_at and minutes empty.
 *
 * @property TimeEntrySource $source
 * @property Carbon $worked_on
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $minutes
 */
#[Fillable(['incident_id', 'user_id', 'minutes', 'note', 'worked_on', 'started_at', 'ended_at', 'source'])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'worked_on' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'source' => TimeEntrySource::class,
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
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->where('source', TimeEntrySource::Timer->value)->whereNull('ended_at');
    }

    public function isRunning(): bool
    {
        return $this->source === TimeEntrySource::Timer && $this->ended_at === null;
    }
}
