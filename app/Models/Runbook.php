<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\RunbookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Step-by-step instructions for a service or category of issue. The body is Markdown.
 */
#[Fillable(['title', 'body', 'service_id', 'category_id', 'created_by', 'updated_by'])]
class Runbook extends Model
{
    /** @use HasFactory<RunbookFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Markdown to HTML with raw HTML stripped and unsafe links (javascript:, data:) removed,
     * so the result is safe to display.
     */
    public static function renderMarkdown(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
