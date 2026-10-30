<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dated event in an RCA's timeline. The actor is free text (a person, a team or a system
 * such as "Grafana alert"), since it often is not a user of this system.
 *
 * @property Carbon $at
 */
#[Fillable(['rca_id', 'at', 'event', 'actor'])]
class RcaTimelineEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rca, $this>
     */
    public function rca(): BelongsTo
    {
        return $this->belongsTo(Rca::class);
    }
}
