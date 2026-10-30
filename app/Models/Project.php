<?php

namespace App\Models;

use App\Enums\PermissionKey;
use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatus;
use App\Enums\SprintState;
use App\Models\Concerns\Auditable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A project groups planned work: sprints, tickets and the people delivering them. Visibility
 * is project-scoped: members, the lead, the owner team and holders of project.manage.
 *
 * @property ProjectStatus $status
 * @property bool $review_required
 * @property Carbon|null $archived_at
 */
#[Fillable(['key', 'name', 'description', 'owner_team_id', 'lead_id', 'status', 'review_required', 'color'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'review_required' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'review_required' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function ownerTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'owner_team_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    /**
     * @return BelongsToMany<User, $this, ProjectMember, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Sprint, $this>
     */
    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * The sprint the board opens on when the caller does not pick one.
     *
     * @return HasOne<Sprint, $this>
     */
    public function activeSprint(): HasOne
    {
        return $this->hasOne(Sprint::class)->where('state', SprintState::Active->value);
    }

    /**
     * The person's role in this project, or null when they only see it through their team.
     */
    public function memberRole(User $user): ?ProjectMemberRole
    {
        $membership = $this->members()->whereKey($user->id)->first();

        return $membership?->pivot?->role;
    }

    /**
     * Projects the user may list: their own, their team's, or all for project managers.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(PermissionKey::ProjectManage->value)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query
                ->where('lead_id', $user->id)
                ->orWhereHas('members', fn (Builder $members): Builder => $members->whereKey($user->id))
                ->orWhereIn('owner_team_id', $user->teams()->select('teams.id'));
        });
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->can(PermissionKey::ProjectManage->value) || $this->lead_id === $user->id) {
            return true;
        }

        if ($this->owner_team_id !== null && $user->teams()->whereKey($this->owner_team_id)->exists()) {
            return true;
        }

        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Who can create, edit, assign and move work in this project.
     */
    public function canContributeBy(User $user): bool
    {
        if ($user->can(PermissionKey::ProjectManage->value) || $this->lead_id === $user->id) {
            return true;
        }

        return $this->memberRole($user)?->canContribute() ?? false;
    }

    /**
     * Who can change the project itself: members, sprints, archive.
     */
    public function canBeManagedBy(User $user): bool
    {
        if ($user->can(PermissionKey::ProjectManage->value) || $this->lead_id === $user->id) {
            return true;
        }

        return $this->memberRole($user)?->canManage() ?? false;
    }

    public function isArchived(): bool
    {
        return $this->status === ProjectStatus::Archived;
    }
}
