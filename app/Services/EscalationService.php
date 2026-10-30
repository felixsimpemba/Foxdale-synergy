<?php

namespace App\Services;

use App\Enums\EscalationTargetType;
use App\Enums\IncidentStatus;
use App\Enums\IncidentUpdateType;
use App\Events\IncidentEscalated;
use App\Exceptions\ApiException;
use App\Models\EscalationLevel;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Walks an incident up its escalation policy. Levels are timed from reported_at; automatic
 * escalation runs only while the incident is unacknowledged, and stops at the last level.
 */
class EscalationService
{
    public function __construct(private OnCallService $onCall) {}

    /**
     * For a new incident: levels due at 0 minutes count as reached (the creation notice covers
     * them) and the next level is scheduled. Does not save.
     */
    public function start(Incident $incident): void
    {
        $levels = $this->levelsFor($incident);
        $reached = $levels->filter(fn (EscalationLevel $level): bool => $level->after_minutes === 0)->last();

        $incident->escalation_level = $reached->level ?? 0;
        $next = $this->nextLevel($levels, $incident->escalation_level);
        $incident->next_escalation_at = $next === null ? null : $incident->reported_at->copy()->addMinutes($next->after_minutes);
    }

    /**
     * Move to the next level now and notify its targets (manual or scheduled).
     */
    public function escalate(Incident $incident, ?User $actor = null): EscalationLevel
    {
        $levels = $this->levelsFor($incident);

        if ($levels->isEmpty()) {
            throw new ApiException('This incident has no escalation policy.', 'no_escalation_policy');
        }

        $level = $this->nextLevel($levels, $incident->escalation_level);

        if ($level === null) {
            throw new ApiException('The incident is already at the last escalation level.', 'no_further_escalation');
        }

        return DB::transaction(function () use ($incident, $actor, $levels, $level): EscalationLevel {
            $recipients = $this->recipients($level, $incident->team);
            $following = $this->nextLevel($levels, $level->level);

            $incident->escalation_level = $level->level;
            $incident->next_escalation_at = $following === null
                ? null
                : now()->addMinutes(max(1, $following->after_minutes - $level->after_minutes));
            $incident->save();

            $names = $recipients->pluck('name')->join(', ') ?: 'nobody available';
            $incident->updates()->create([
                'user_id' => $actor?->id,
                'type' => IncidentUpdateType::System,
                'body' => "Escalated to level {$level->level} ({$level->target_type->value}): {$names}",
            ]);

            IncidentEscalated::dispatch($incident, $level, $recipients, $actor);

            return $level;
        });
    }

    /**
     * Scheduled check: escalate an unacknowledged incident whose next level is due.
     */
    public function escalateIfDue(Incident $incident, Carbon $now): void
    {
        if ($incident->status !== IncidentStatus::Reported
            || $incident->next_escalation_at === null
            || $incident->next_escalation_at->greaterThan($now)) {
            return;
        }

        try {
            $this->escalate($incident);
        } catch (ApiException) {
            // Policy removed or levels changed since scheduling: stop escalating.
            $incident->forceFill(['next_escalation_at' => null])->save();
        }
    }

    /**
     * The people a level notifies. Team-based targets without a target_id use $team.
     *
     * @return Collection<int, User>
     */
    public function recipients(EscalationLevel $level, ?Team $team): Collection
    {
        $targetTeam = $level->target_id !== null && ! $level->target_type->needsTargetId()
            ? Team::find($level->target_id)
            : $team;

        $users = match ($level->target_type) {
            EscalationTargetType::OnCall => $targetTeam === null ? [] : [$this->onCall->currentFor($targetTeam)],
            EscalationTargetType::TeamLead => [$targetTeam?->lead],
            EscalationTargetType::Team => $targetTeam?->members()->get()->all() ?? [],
            EscalationTargetType::User => [User::find($level->target_id)],
            EscalationTargetType::Role => User::query()->whereHas('roles', fn ($query) => $query->whereKey($level->target_id))->get()->all(),
        };

        return new Collection(array_values(array_filter(
            $users,
            fn (?User $user): bool => $user !== null && $user->is_active,
        )));
    }

    /**
     * @return Collection<int, EscalationLevel>
     */
    private function levelsFor(Incident $incident): Collection
    {
        return $incident->escalationPolicy?->levelsFor($incident->severity) ?? new Collection;
    }

    /**
     * @param  Collection<int, EscalationLevel>  $levels
     */
    private function nextLevel(Collection $levels, int $current): ?EscalationLevel
    {
        return $levels->first(fn (EscalationLevel $level): bool => $level->level > $current);
    }
}
