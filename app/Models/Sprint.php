<?php

namespace App\Models;

use App\Enums\SprintState;
use App\Models\Concerns\Auditable;
use Database\Factories\SprintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A planned or active sprint. Tasks without a sprint live in the project backlog.
 *
 * @property SprintState $state
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $completed_at
 */
#[Fillable(['project_id', 'name', 'goal', 'starts_on', 'ends_on', 'state'])]
class Sprint extends Model
{
    /** @use HasFactory<SprintFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'planned',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => SprintState::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isActive(): bool
    {
        return $this->state === SprintState::Active;
    }

    public function isCompleted(): bool
    {
        return $this->state === SprintState::Completed;
    }
}
