<?php

namespace App\Models;

use App\Enums\AssignmentMode;
use App\Enums\Severity;
use App\Models\Concerns\Auditable;
use Database\Factories\RoutingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decides where an incident goes. Rules are tried by priority (lowest first); empty match
 * fields match anything. An empty team routes to the service's owner team.
 *
 * @property AssignmentMode $assignment_mode
 * @property list<int>|null $notify_channel_ids
 */
#[Fillable([
    'name', 'priority', 'category_id', 'service_id', 'min_severity', 'max_severity', 'team_id',
    'escalation_policy_id', 'assignment_mode', 'notify_channel_ids', 'is_active',
])]
class RoutingRule extends Model
{
    /** @use HasFactory<RoutingRuleFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'min_severity' => 'integer',
            'max_severity' => 'integer',
            'assignment_mode' => AssignmentMode::class,
            'notify_channel_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<EscalationPolicy, $this>
     */
    public function escalationPolicy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class);
    }

    /**
     * Active rules that match, best (lowest priority number) first.
     *
     * @param  Builder<RoutingRule>  $query
     */
    public function scopeMatching(Builder $query, ?int $categoryId, ?int $serviceId, Severity $severity): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('category_id')->orWhere('category_id', $categoryId))
            ->where(fn (Builder $query) => $query->whereNull('service_id')->orWhere('service_id', $serviceId))
            ->where(fn (Builder $query) => $query->whereNull('min_severity')->orWhere('min_severity', '<=', $severity->value))
            ->where(fn (Builder $query) => $query->whereNull('max_severity')->orWhere('max_severity', '>=', $severity->value))
            ->orderBy('priority')
            ->orderBy('id');
    }
}
