<?php

namespace App\Models;

use App\Enums\Severity;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Severity $severity
 * @property int $ack_minutes
 * @property int $mitigate_minutes
 * @property int $resolve_minutes
 */
#[Fillable(['severity', 'ack_minutes', 'mitigate_minutes', 'resolve_minutes'])]
class SlaPolicy extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'ack_minutes' => 'integer',
            'mitigate_minutes' => 'integer',
            'resolve_minutes' => 'integer',
        ];
    }
}
