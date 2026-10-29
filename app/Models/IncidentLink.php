<?php

namespace App\Models;

use App\Enums\IncidentLinkType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'related_incident_id', 'type', 'created_by'])]
class IncidentLink extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IncidentLinkType::class,
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
     * @return BelongsTo<Incident, $this>
     */
    public function relatedIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'related_incident_id');
    }
}
