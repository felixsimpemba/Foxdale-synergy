<?php

namespace App\Services\Mentions;

use App\Enums\PermissionKey;
use App\Models\Incident;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Who may be mentioned on an incident or task, and who a comment mentions. A mention is plain
 * text ("@Grace Mwansa") so it survives editing; the names are resolved here against the
 * people who can actually open the subject.
 */
class Mentioner
{
    /**
     * Active people who can open this incident: its reporter, its assignee, its watchers,
     * anyone with incident.view.all, and members of the owning team or service team who
     * have incident.view.team. Mentioning anybody else would leak the incident title.
     *
     * @return Collection<int, User>
     */
    public function mentionable(Incident $incident, User $author): Collection
    {
        $incident->loadMissing('service');
        $teamIds = array_filter([$incident->team_id, $incident->service?->owner_team_id]);

        $teamViewers = $teamIds === [] ? collect() : User::query()
            ->where('is_active', true)
            ->where($this->havingPermission(PermissionKey::IncidentViewTeam->value))
            ->whereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $teamIds))
            ->pluck('id');

        $ids = collect([$incident->reporter_id, $incident->assignee_id])
            ->merge($incident->watchers()->pluck('users.id'))
            ->merge(User::query()
                ->where('is_active', true)
                ->where($this->havingPermission(PermissionKey::IncidentViewAll->value))
                ->pluck('id'))
            ->merge($teamViewers)
            ->filter()
            ->unique();

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }

    /**
     * Active people who can open this task: the project lead, its members, its owner team and
     * anyone with project.manage. Mentioning anybody else would leak the project title.
     *
     * @return Collection<int, User>
     */
    public function mentionableOnTask(Task $task): Collection
    {
        $project = Project::query()->findOrFail($task->project_id);

        $teamViewers = $project->owner_team_id === null ? collect() : User::query()
            ->where('is_active', true)
            ->whereHas('teams', fn (Builder $teams) => $teams->whereKey($project->owner_team_id))
            ->pluck('id');

        $ids = collect([$project->lead_id])
            ->merge($project->members()->pluck('users.id'))
            ->merge(User::query()
                ->where('is_active', true)
                ->where($this->havingPermission(PermissionKey::ProjectManage->value))
                ->pluck('id'))
            ->merge($teamViewers)
            ->filter()
            ->unique();

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }

    /**
     * A permission can be granted directly or through a role; match both.
     */
    private function havingPermission(string $permission): Closure
    {
        return fn (Builder $query) => $query
            ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('name', $permission))
            ->orWhereHas('roles.permissions', fn (Builder $permissions) => $permissions->where('name', $permission));
    }

    /**
     * The people an @mention in the text refers to. Longest names first, so "@Ada Phiri"
     * wins over "@Ada" when both exist.
     *
     * @param  Collection<int, User>  $people
     * @return Collection<int, User>
     */
    public function mentionedIn(?string $body, Collection $people): Collection
    {
        $remaining = (string) $body;
        /** @var Collection<int, User> $found */
        $found = new Collection;

        foreach ($people->sortByDesc(fn (User $person): int => mb_strlen($person->name)) as $person) {
            $name = trim($person->name);

            if ($name === '') {
                continue;
            }

            $pattern = '/@'.preg_quote($name, '/').'(?![\pL\pN])/u';

            if (preg_match($pattern, $remaining) === 1) {
                $found->push($person);
                // Blank the match so a shorter name cannot match inside the same mention.
                $remaining = (string) preg_replace($pattern, '@', $remaining, 1);
            }
        }

        return $found;
    }

    /**
     * Names to show on a notification, e.g. "Grace Mwansa and Ada Phiri".
     *
     * @param  SupportCollection<int, User>|Collection<int, User>  $people
     */
    public function names(SupportCollection|Collection $people): string
    {
        $names = $people->pluck('name')->all();

        return match (count($names)) {
            0 => '',
            1 => $names[0],
            2 => "{$names[0]} and {$names[1]}",
            default => implode(', ', array_slice($names, 0, -1)).' and '.end($names),
        };
    }
}
