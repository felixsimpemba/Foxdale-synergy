<?php

namespace App\Models;

use App\Enums\ServiceCriticality;
use App\Models\Concerns\Auditable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'description', 'owner_team_id', 'criticality', 'runbook_url', 'is_active'])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use Auditable, HasFactory;

    /**
     * Mirrors the column defaults so a just-created service serializes correctly.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'criticality' => 'medium',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criticality' => ServiceCriticality::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function ownerTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'owner_team_id');
    }
}
