<?php

namespace App\Models;

use App\Enums\SlaEventType;
use App\Enums\SlaMetric;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A warning (80% of a target elapsed) or breach, raised at most once per incident and metric.
 *
 * @property SlaMetric $metric
 * @property SlaEventType $type
 * @property Carbon $due_at
 * @property Carbon $occurred_at
 */
#[Fillable(['incident_id', 'metric', 'type', 'due_at', 'occurred_at'])]
class IncidentSlaEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => SlaMetric::class,
            'type' => SlaEventType::class,
            'due_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
