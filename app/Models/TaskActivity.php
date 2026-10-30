<?php

namespace App\Models;

use App\Enums\TaskActivityType;
use Database\Factories\TaskActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One system entry in a task timeline (status change, assignment, review decision).
 * Comments are merged into the same feed from their own table later.
 *
 * @property TaskActivityType $type
 * @property Carbon $created_at
 */
#[Fillable(['task_id', 'user_id', 'type', 'from_value', 'to_value', 'meta'])]
class TaskActivity extends Model
{
    /** @use HasFactory<TaskActivityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskActivityType::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
