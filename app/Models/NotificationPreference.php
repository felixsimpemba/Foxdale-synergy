<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user notification settings: which channels are on, per-event overrides, and quiet
 * hours. Absent rows mean the defaults from NotificationEvent.
 *
 * @property array<string, mixed> $settings
 */
#[Fillable(['user_id', 'settings'])]
class NotificationPreference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
