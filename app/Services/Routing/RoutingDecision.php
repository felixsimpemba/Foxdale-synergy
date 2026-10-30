<?php

namespace App\Services\Routing;

use App\Models\EscalationPolicy;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Models\User;

/**
 * Where an incident goes: the team, who is assigned, the rule that matched (none means the
 * default team), the escalation policy, and the chat channels the rule asks to notify.
 */
final readonly class RoutingDecision
{
    /**
     * @param  list<int>  $notifyChannelIds
     */
    public function __construct(
        public ?Team $team,
        public ?User $assignee,
        public ?User $onCall,
        public ?RoutingRule $rule,
        public ?EscalationPolicy $escalationPolicy,
        public array $notifyChannelIds = [],
    ) {}
}
