<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskReviewState;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Concerns\Auditable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A ticket in a project. Every task has a project-scoped number and a "KEY-number" reference;
 * subtasks are one level deep (parent_id set, parent has no parent itself).
 *
 * @property TaskType $type
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property TaskReviewState $review_state
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 */
#[Fillable([
    'project_id', 'sprint_id', 'parent_id', 'title', 'description', 'type', 'status', 'priority',
    'assignee_id', 'reporter_id', 'reviewer_id', 'story_points', 'due_date', 'is_blocked',
    'blocked_reason',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'task',
        'status' => 'todo',
        'priority' => 'medium',
        'review_state' => 'none',
        'is_blocked' => false,
    ];

    /**
     * Number, reference and position are computed or board noise, not edits worth auditing.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['number', 'ref', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'review_state' => TaskReviewState::class,
            'story_points' => 'integer',
            'due_date' => 'date',
            'is_blocked' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public static function makeRef(string $projectKey, int $number): string
    {
        return $projectKey.'-'.$number;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Sprint, $this>
     */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return HasMany<TaskActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderBy('created_at')->orderBy('id');
    }

    public function isFinished(): bool
    {
        return $this->status === TaskStatus::Done;
    }

    public function isSubtask(): bool
    {
        return $this->parent_id !== null;
    }
}
