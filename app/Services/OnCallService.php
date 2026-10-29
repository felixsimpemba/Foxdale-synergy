<?php

namespace App\Services;

use App\Models\OnCallShift;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who is on call for a team at a given moment: an override shift wins over a regular shift,
 * then the most recently started shift. Inactive schedules and users are ignored.
 */
class OnCallService
{
    public function currentShift(Team $team, ?Carbon $at = null): ?OnCallShift
    {
        $at ??= now();

        return OnCallShift::query()
            ->whereHas('schedule', fn ($query) => $query->where('team_id', $team->id)->where('is_active', true))
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->with('user', 'schedule')
            ->orderByDesc('is_override')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function currentFor(Team $team, ?Carbon $at = null): ?User
    {
        return $this->currentShift($team, $at)?->user;
    }
}
