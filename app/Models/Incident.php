<?php

namespace App\Models;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\PermissionKey;
use App\Enums\RcaStatus;
use App\Enums\Severity;
use App\Models\Concerns\Auditable;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An incident is also the ticket: reference, status, owner, timeline, time tracking and SLA.
 *
 * @property Severity $severity
 * @property IncidentStatus $status
 * @property IncidentSource $source
 * @property Carbon $reported_at
 * @property Carbon|null $started_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $mitigated_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $ack_due_at
 * @property Carbon|null $mitigate_due_at
 * @property Carbon|null $resolve_due_at
 * @property bool $sla_breached
 * @property int $escalation_level
 * @property Carbon|null $next_escalation_at
 */
#[Fillable([
    'title', 'description', 'impact', 'severity', 'status', 'category_id', 'service_id', 'team_id',
    'reporter_id', 'assignee_id', 'source', 'fingerprint', 'started_at', 'reported_at',
])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'occurrences' => 1,
        'sla_breached' => false,
        'reopened_count' => 0,
        'escalation_level' => 0,
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['ref', 'mattermost_post_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'status' => IncidentStatus::class,
            'source' => IncidentSource::class,
            'started_at' => 'datetime',
            'reported_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'mitigated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'ack_due_at' => 'datetime',
            'mitigate_due_at' => 'datetime',
            'resolve_due_at' => 'datetime',
            'sla_breached' => 'boolean',
            'escalation_level' => 'integer',
            'next_escalation_at' => 'datetime',
        ];
    }

    public static function makeRef(int $id, Carbon $reportedAt): string
    {
        return sprintf('INC-%d-%05d', $reportedAt->year, $id);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<RoutingRule, $this>
     */
    public function routingRule(): BelongsTo
    {
        return $this->belongsTo(RoutingRule::class);
    }

    /**
     * @return BelongsTo<EscalationPolicy, $this>
     */
    public function escalationPolicy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class);
    }

    /**
     * @return HasMany<IncidentUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class);
    }

    /**
     * @return HasMany<StatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(StatusHistory::class);
    }

    /**
     * @return HasMany<IncidentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(IncidentAssignment::class);
    }

    /**
     * Links created from this incident to others.
     *
     * @return HasMany<IncidentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(IncidentLink::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'incident_watchers')->withTimestamps();
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasOne<IncidentResolution, $this>
     */
    public function resolution(): HasOne
    {
        return $this->hasOne(IncidentResolution::class);
    }

    /**
     * @return HasOne<Rca, $this>
     */
    public function rca(): HasOne
    {
        return $this->hasOne(Rca::class);
    }

    /**
     * The RCA only once approved, as shown in the knowledge base.
     *
     * @return HasOne<Rca, $this>
     */
    public function approvedRca(): HasOne
    {
        return $this->hasOne(Rca::class)->where('status', RcaStatus::Approved->value);
    }

    /**
     * @return HasMany<IncidentSlaEvent, $this>
     */
    public function slaEvents(): HasMany
    {
        return $this->hasMany(IncidentSlaEvent::class);
    }

    /**
     * @param  Builder<Incident>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', IncidentStatus::openValues());
    }

    /**
     * Incidents the user may see (specification section 4, scoping rules):
     * all with incident.view.all; otherwise those reported by or assigned to them, plus,
     * with incident.view.team, those routed to or on services owned by their teams.
     *
     * @param  Builder<Incident>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionKey::IncidentViewAll->value)) {
            return;
        }

        $canSeeOwn = $user->can(PermissionKey::IncidentViewOwn->value);
        $canSeeTeam = $user->can(PermissionKey::IncidentViewTeam->value);
        $teamIds = $canSeeTeam ? $user->teams()->pluck('teams.id')->all() : [];

        $query->where(function (Builder $query) use ($user, $canSeeOwn, $canSeeTeam, $teamIds): void {
            // Start from "nothing", so a user with none of the permissions sees no incidents.
            $query->whereRaw('1 = 0');

            // Qualified, so the scope also works on queries that join other tables.
            if ($canSeeOwn) {
                $query->orWhere($query->qualifyColumn('reporter_id'), $user->id)
                    ->orWhere($query->qualifyColumn('assignee_id'), $user->id);
            }

            if ($canSeeTeam && $teamIds !== []) {
                $query->orWhereIn($query->qualifyColumn('team_id'), $teamIds)
                    ->orWhereHas('service', fn (Builder $service) => $service->whereIn('owner_team_id', $teamIds));
            }
        });
    }

    /**
     * Adds work_minutes (time_entries_sum_minutes) and the time of the last status change.
     *
     * @param  Builder<Incident>  $query
     */
    public function scopeWithTimings(Builder $query): void
    {
        $query->withSum('timeEntries', 'minutes')->withMax('statusHistory', 'changed_at');
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * SEV1 and SEV2 incidents close only with an approved RCA.
     */
    public function hasApprovedRca(): bool
    {
        return $this->rca()->where('status', RcaStatus::Approved->value)->exists();
    }

    public function secondsToAcknowledge(): ?int
    {
        return $this->secondsSinceReported($this->acknowledged_at);
    }

    public function secondsToMitigate(): ?int
    {
        return $this->secondsSinceReported($this->mitigated_at);
    }

    public function secondsToResolve(): ?int
    {
        return $this->secondsSinceReported($this->resolved_at);
    }

    private function secondsSinceReported(?Carbon $moment): ?int
    {
        return $moment === null ? null : (int) $this->reported_at->diffInSeconds($moment);
    }
}
