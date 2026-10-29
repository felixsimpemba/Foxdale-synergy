<?php

namespace App\Models;

use App\Enums\Severity;
use App\Models\Concerns\Auditable;
use Database\Factories\EscalationPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordered levels that are notified in turn while an incident stays unacknowledged.
 */
#[Fillable(['name', 'description'])]
class EscalationPolicy extends Model
{
    /** @use HasFactory<EscalationPolicyFactory> */
    use Auditable, HasFactory;

    /**
     * @return HasMany<EscalationLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(EscalationLevel::class)->orderBy('level');
    }

    /**
     * Levels that apply to an incident of this severity, in order.
     *
     * @return Collection<int, EscalationLevel>
     */
    public function levelsFor(Severity $severity): Collection
    {
        return $this->levels
            ->filter(fn (EscalationLevel $level): bool => $level->appliesTo($severity))
            ->values();
    }
}
