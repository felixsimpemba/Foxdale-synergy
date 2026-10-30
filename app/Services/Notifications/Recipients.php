<?php

namespace App\Services\Notifications;

use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who gets told about what (specification section 12): the people involved in an incident,
 * and the incident managers for review and breach notices.
 */
final class Recipients
{
    /**
     * @return Collection<int, User>
     */
    public static function incidentManagers(): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', RoleName::IncidentManager->value))
            ->where('is_active', true)
            ->get();
    }

    public static function teamLead(Incident $incident): ?User
    {
        $incident->loadMissing('team.lead');

        return $incident->team instanceof Team ? $incident->team->lead : null;
    }

    /**
     * Reported it, watching it or working on it: everyone who cares about its status.
     *
     * @return Collection<int, User>
     */
    public static function involvedIn(Incident $incident): \Illuminate\Support\Collection
    {
        $incident->loadMissing(['reporter', 'assignee', 'watchers']);

        return collect([$incident->reporter, $incident->assignee])
            ->merge($incident->watchers)
            ->filter()
            ->unique('id')
            ->values();
    }
}
