<?php

namespace App\Services;

use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatus;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Project membership is kept in project_members; the lead is always a manager member so
 * assignment and review checks have one source of truth.
 */
class ProjectService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Project
    {
        return DB::transaction(function () use ($actor, $data): Project {
            $leadId = $data['lead_id'] ?? $actor->id;

            $project = Project::create([
                ...$data,
                'lead_id' => $leadId,
            ]);

            $project->members()->syncWithoutDetaching([
                $leadId => ['role' => ProjectMemberRole::Manager->value],
            ]);

            $this->audit->record('project.member_added', $project, [
                'user_id' => $leadId,
                'role' => ProjectMemberRole::Manager->value,
            ], $actor);

            return $project->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, User $actor, array $data): Project
    {
        return DB::transaction(function () use ($project, $actor, $data): Project {
            $oldLeadId = $project->lead_id;

            $project->fill($data);

            if (array_key_exists('status', $data)) {
                $project->archived_at = $project->status === ProjectStatus::Archived
                    ? ($project->archived_at ?? now())
                    : null;
            }

            $project->save();

            if ($project->lead_id !== null && $project->lead_id !== $oldLeadId) {
                $project->members()->syncWithoutDetaching([
                    $project->lead_id => ['role' => ProjectMemberRole::Manager->value],
                ]);

                $this->audit->record('project.lead_changed', $project, [
                    'from_user_id' => $oldLeadId,
                    'to_user_id' => $project->lead_id,
                ], $actor);
            }

            return $project->refresh();
        });
    }

    public function addMember(Project $project, User $user, ProjectMemberRole $role, User $actor): void
    {
        $project->members()->syncWithoutDetaching([
            $user->id => ['role' => $role->value],
        ]);

        $this->audit->record('project.member_added', $project, [
            'user_id' => $user->id,
            'role' => $role->value,
        ], $actor);
    }

    public function removeMember(Project $project, User $user, User $actor): void
    {
        if ($project->lead_id === $user->id) {
            throw new ApiException('Choose another lead before removing this person.', 'lead_member');
        }

        $project->members()->detach($user->id);

        $this->audit->record('project.member_removed', $project, ['user_id' => $user->id], $actor);
    }
}
