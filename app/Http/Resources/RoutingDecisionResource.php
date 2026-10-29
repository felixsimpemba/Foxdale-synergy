<?php

namespace App\Http\Resources;

use App\Enums\Severity;
use App\Models\EscalationLevel;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\Routing\RoutingDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where an incident would go: team, assignee, on-call person, the rule that matched, the
 * escalation contacts per level and the chat channels.
 *
 * @property RoutingDecision $resource
 */
class RoutingDecisionResource extends JsonResource
{
    public function __construct(RoutingDecision $decision, private Severity $severity)
    {
        parent::__construct($decision);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $decision = $this->resource;
        $escalation = app(EscalationService::class);
        $levels = $decision->escalationPolicy?->levelsFor($this->severity) ?? collect();

        return [
            'team' => $decision->team === null ? null : ['id' => $decision->team->id, 'name' => $decision->team->name],
            'assignee' => $this->person($decision->assignee),
            'on_call' => $this->person($decision->onCall),
            'rule' => $decision->rule === null ? null : ['id' => $decision->rule->id, 'name' => $decision->rule->name],
            'escalation_policy' => $decision->escalationPolicy === null
                ? null
                : ['id' => $decision->escalationPolicy->id, 'name' => $decision->escalationPolicy->name],
            'escalation' => $levels->map(fn (EscalationLevel $level): array => [
                'level' => $level->level,
                'after_minutes' => $level->after_minutes,
                'target_type' => $level->target_type,
                'contacts' => $escalation->recipients($level, $decision->team)->map(fn (User $user) => $this->person($user))->values(),
            ])->values(),
            'notify_channel_ids' => $decision->notifyChannelIds,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
