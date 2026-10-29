<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per status change, written automatically, so time per stage is always known.
 *
 * @property IncidentStatus|null $from_status
 * @property IncidentStatus $to_status
 * @property Carbon $changed_at
 */
#[Table('status_history')]
#[Fillable(['incident_id', 'from_status', 'to_status', 'user_id', 'note', 'changed_at', 'seconds_in_previous'])]
class StatusHistory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => IncidentStatus::class,
            'to_status' => IncidentStatus::class,
            'changed_at' => 'datetime',
            'seconds_in_previous' => 'integer',
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
}
