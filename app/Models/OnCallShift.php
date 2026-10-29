<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\OnCallShiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period when one person is on call. Overrides win over regular shifts.
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_override
 */
#[Fillable(['on_call_schedule_id', 'user_id', 'starts_at', 'ends_at', 'is_override'])]
class OnCallShift extends Model
{
    /** @use HasFactory<OnCallShiftFactory> */
    use Auditable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_override' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<OnCallSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OnCallSchedule::class, 'on_call_schedule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
