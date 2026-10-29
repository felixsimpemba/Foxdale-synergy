<?php

namespace App\Models;

use App\Enums\EscalationTargetType;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property EscalationTargetType $target_type
 * @property int $level
 * @property int $after_minutes
 * @property int|null $max_severity
 */
#[Fillable(['escalation_policy_id', 'level', 'after_minutes', 'target_type', 'target_id', 'max_severity'])]
class EscalationLevel extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'after_minutes' => 'integer',
            'target_type' => EscalationTargetType::class,
            'target_id' => 'integer',
            'max_severity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<EscalationPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class, 'escalation_policy_id');
    }

    public function appliesTo(Severity $severity): bool
    {
        return $this->max_severity === null || $severity->value <= $this->max_severity;
    }
}
