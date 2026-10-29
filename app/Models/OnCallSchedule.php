<?php

namespace App\Models;

use App\Enums\RotationType;
use App\Models\Concerns\Auditable;
use Database\Factories\OnCallScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'name', 'rotation_type', 'timezone', 'is_active'])]
class OnCallSchedule extends Model
{
    /** @use HasFactory<OnCallScheduleFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rotation_type' => RotationType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<OnCallShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(OnCallShift::class);
    }
}
