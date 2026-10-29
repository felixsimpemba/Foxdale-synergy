<?php

namespace App\Services;

use App\Enums\AssignmentMode;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\IncidentAssignment;
use App\Models\RoutingRule;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\Routing\RoutingDecision;
use Illuminate\Database\Eloquent\Collection;

/**
 * The routing algorithm (specification section 5):
 *
 * 1. Take the first active rule, by priority, whose category, service and severity range
 *    match; an empty field matches anything.
 * 2. The rule names the team (empty = the service's owner team), the escalation policy,
 *    the chat channels and the assignment mode.
 * 3. Resolve the assignee from the mode; the fallback is always the team lead.
 * 4. With no matching rule, route to the default team (NOC) using the on-call chain.
 */
class RoutingService
{
    public function __construct(private OnCallService $onCall) {}

    public function decide(?Service $service, ?int $categoryId, Severity $severity): RoutingDecision
    {
        $rule = RoutingRule::query()
            ->matching($categoryId, $service?->id, $severity)
            ->with('team', 'escalationPolicy.levels')
            ->first();

        $team = $rule === null
            ? $this->defaultTeam()
            : ($rule->team ?? $service->ownerTeam ?? $this->defaultTeam());

        $onCall = $team === null ? null : $this->onCall->currentFor($team);
        $mode = $rule->assignment_mode ?? AssignmentMode::OnCall;

        return new RoutingDecision(
            team: $team,
            assignee: $team === null ? null : $this->pickAssignee($team, $mode, $onCall),
            onCall: $onCall,
            rule: $rule,
            escalationPolicy: $rule?->escalationPolicy,
            notifyChannelIds: $rule->notify_channel_ids ?? [],
        );
    }

    /**
     * Apply a routing decision to a new incident. Does not save.
     */
    public function route(Incident $incident): RoutingDecision
    {
        $decision = $this->decide($incident->service, $incident->category_id, $incident->severity);

        $incident->team_id = $decision->team?->id;
        $incident->assignee_id = $decision->assignee?->id;
        $incident->routing_rule_id = $decision->rule?->id;
        $incident->escalation_policy_id = $decision->escalationPolicy?->id;

        return $decision;
    }

    public function defaultTeam(): ?Team
    {
        return Team::firstWhere('name', config('incidents.routing.default_team'));
    }

    private function pickAssignee(Team $team, AssignmentMode $mode, ?User $onCall): ?User
    {
        return match ($mode) {
            AssignmentMode::OnCall => $onCall ?? $this->roundRobin($team) ?? $this->lead($team),
            AssignmentMode::RoundRobin => $this->roundRobin($team) ?? $this->lead($team),
            AssignmentMode::LeastLoaded => $this->leastLoaded($team) ?? $this->lead($team),
            AssignmentMode::None => null,
        };
    }

    /**
     * The active member assigned an incident for this team least recently (never first).
     */
    private function roundRobin(Team $team): ?User
    {
        $members = $this->activeMembers($team);

        if ($members->isEmpty()) {
            return null;
        }

        $lastAssignment = IncidentAssignment::query()
            ->where('team_id', $team->id)
            ->whereIn('to_user_id', $members->modelKeys())
            ->groupBy('to_user_id')
            ->selectRaw('to_user_id, max(id) as last_assignment_id')
            ->pluck('last_assignment_id', 'to_user_id');

        return $members
            ->sortBy(fn (User $member): array => [(int) ($lastAssignment[$member->id] ?? 0), $member->id])
            ->first();
    }

    /**
     * The active member with the fewest open incidents assigned.
     */
    private function leastLoaded(Team $team): ?User
    {
        $members = $this->activeMembers($team);

        if ($members->isEmpty()) {
            return null;
        }

        $openCounts = Incident::query()
            ->open()
            ->whereIn('assignee_id', $members->modelKeys())
            ->groupBy('assignee_id')
            ->selectRaw('assignee_id, count(*) as open_count')
            ->pluck('open_count', 'assignee_id');

        return $members
            ->sortBy(fn (User $member): array => [(int) ($openCounts[$member->id] ?? 0), $member->id])
            ->first();
    }

    private function lead(Team $team): ?User
    {
        $lead = $team->lead;

        return $lead !== null && $lead->is_active ? $lead : null;
    }

    /**
     * @return Collection<int, User>
     */
    private function activeMembers(Team $team): Collection
    {
        return $team->members()->where('is_active', true)->get();
    }
}
