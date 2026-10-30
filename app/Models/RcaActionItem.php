<?php

namespace App\Models;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\ActionItemType;
use App\Models\Concerns\Auditable;
use App\Services\SettingsService;
use Database\Factories\RcaActionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A corrective, preventive, detection or process task that follows from an RCA, with an
 * owner and a due date. Overdue items are reminded daily to the owner.
 *
 * @property ActionItemType $type
 * @property ActionItemPriority $priority
 * @property ActionItemStatus $status
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_reminded_at
 */
#[Fillable(['rca_id', 'type', 'title', 'owner_id', 'due_date', 'priority', 'status', 'note'])]
class RcaActionItem extends Model
{
    /** @use HasFactory<RcaActionItemFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'medium',
        'status' => 'open',
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['last_reminded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActionItemType::class,
            'priority' => ActionItemPriority::class,
            'status' => ActionItemStatus::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rca, $this>
     */
    public function rca(): BelongsTo
    {
        return $this->belongsTo(Rca::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @param  Builder<RcaActionItem>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', ActionItemStatus::unfinishedValues());
    }

    /**
     * Unfinished and due before today, in the display timezone.
     *
     * @param  Builder<RcaActionItem>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->unfinished()->whereNotNull('due_date')->where('due_date', '<', self::today());
    }

    public function isOverdue(): bool
    {
        return ! $this->status->isFinished() && $this->due_date !== null && $this->due_date->toDateString() < self::today();
    }

    /**
     * Today's date where the team works, so an item due today is not overdue until tomorrow.
     */
    public static function today(): string
    {
        return now(app(SettingsService::class)->get('display_timezone'))->toDateString();
    }
}
