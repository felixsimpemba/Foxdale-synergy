<?php

namespace App\Models;

use Database\Factories\TaskCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment on a task. Comments form threads one level deep: a reply's parent_id is the
 * top-level comment. Mentions are resolved server-side from the text.
 *
 * @property int|null $parent_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['task_id', 'parent_id', 'user_id', 'body'])]
class TaskComment extends Model
{
    /** @use HasFactory<TaskCommentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
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

    /**
     * @return BelongsTo<TaskComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Replies, oldest first. Includes deleted ones only when loaded withTrashed.
     *
     * @return HasMany<TaskComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * People mentioned with @name in the text.
     *
     * @return BelongsToMany<User, $this>
     */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_comment_mentions')->withTimestamps();
    }
}
