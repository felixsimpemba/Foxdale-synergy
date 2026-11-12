<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp group the admin has chosen to receive incident alerts. The group list itself
 * comes from the WAPI gateway; this table stores which ones are switched on.
 *
 * @property string $group_jid
 * @property string $subject
 * @property bool $is_active
 */
#[Table('whatsapp_groups')]
#[Fillable(['group_jid', 'subject', 'is_active'])]
class WhatsAppGroup extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<WhatsAppGroup>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
