<?php

namespace App\Models;

use App\Enums\RcaStatus;
use App\Enums\RecurrenceRisk;
use App\Enums\RootCauseCategory;
use App\Models\Concerns\Auditable;
use Database\Factories\RcaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Root cause analysis of one incident (specification section 8). Written as a draft, sent
 * for review, then approved or rejected by someone other than the author. SEV1 and SEV2
 * incidents close only with an approved RCA.
 *
 * @property RcaStatus $status
 * @property RootCauseCategory|null $root_cause_category
 * @property RecurrenceRisk|null $recurrence_risk
 * @property list<string>|null $contributing_factors
 * @property list<array{why: string, answer: string}>|null $five_whys
 * @property list<string>|null $went_well
 * @property list<string>|null $went_wrong
 * @property Carbon|null $due_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $last_reminded_at
 */
#[Fillable([
    'incident_id', 'summary', 'impact', 'detection', 'root_cause', 'root_cause_category',
    'contributing_factors', 'five_whys', 'went_well', 'went_wrong', 'lessons_learned',
    'recurrence_risk', 'author_id', 'due_at',
])]
class Rca extends Model
{
    /** @use HasFactory<RcaFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
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
            'status' => RcaStatus::class,
            'root_cause_category' => RootCauseCategory::class,
            'recurrence_risk' => RecurrenceRisk::class,
            'contributing_factors' => 'array',
            'five_whys' => 'array',
            'went_well' => 'array',
            'went_wrong' => 'array',
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'last_reminded_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return HasMany<RcaTimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(RcaTimelineEvent::class)->orderBy('at')->orderBy('id');
    }

    /**
     * @return HasMany<RcaActionItem, $this>
     */
    public function actionItems(): HasMany
    {
        return $this->hasMany(RcaActionItem::class)->orderBy('id');
    }

    /**
     * What still stops approval, keyed by field, empty when it can be approved: a root
     * cause, at least one action item, and an owner and due date on every item.
     *
     * @return array<string, list<string>>
     */
    public function approvalProblems(): array
    {
        $problems = [];

        if (blank($this->root_cause)) {
            $problems['root_cause'] = ['Describe the root cause.'];
        }

        if ($this->root_cause_category === null) {
            $problems['root_cause_category'] = ['Choose a root cause category.'];
        }

        $items = $this->relationLoaded('actionItems') ? $this->actionItems : $this->actionItems()->get();

        if ($items->isEmpty()) {
            $problems['action_items'] = ['Add at least one action item.'];
        }

        foreach ($items as $item) {
            if ($item->owner_id === null || $item->due_date === null) {
                $problems['action_items'][] = "\"{$item->title}\" needs an owner and a due date.";
            }
        }

        return $problems;
    }

    public function isOverdue(): bool
    {
        return $this->status !== RcaStatus::Approved && $this->due_at !== null && $this->due_at->isPast();
    }
}
