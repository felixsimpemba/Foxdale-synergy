<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'description', 'lead_user_id'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use Auditable, HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_lead')->withTimestamps();
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'owner_team_id');
    }

    /**
     * Add a member, or update an existing one. A team has at most one lead, kept in
     * sync between the pivot flag and teams.lead_user_id.
     */
    public function addMember(User $user, bool $isLead = false): void
    {
        DB::transaction(function () use ($user, $isLead): void {
            if ($isLead) {
                $this->members()->newPivotStatement()
                    ->where('team_id', $this->id)
                    ->update(['is_lead' => false]);
            }

            $this->members()->syncWithoutDetaching([$user->id => ['is_lead' => $isLead]]);

            if ($isLead) {
                $this->update(['lead_user_id' => $user->id]);
            } elseif ($this->lead_user_id === $user->id) {
                $this->update(['lead_user_id' => null]);
            }
        });
    }

    public function removeMember(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->members()->detach($user->id);

            if ($this->lead_user_id === $user->id) {
                $this->update(['lead_user_id' => null]);
            }
        });
    }
}
