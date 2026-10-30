<?php

namespace App\Enums;

/**
 * Who an escalation level notifies. For on_call, team_lead and team, an empty target_id
 * means the incident's own team.
 */
enum EscalationTargetType: string
{
    case OnCall = 'on_call';
    case TeamLead = 'team_lead';
    case Team = 'team';
    case User = 'user';
    case Role = 'role';

    public function needsTargetId(): bool
    {
        return in_array($this, [self::User, self::Role], true);
    }
}
