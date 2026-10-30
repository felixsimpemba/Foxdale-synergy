<?php

namespace App\Models;

use App\Enums\ProjectMemberRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The membership row behind Project::members(). The table has its own id, so it increments
 * like a normal model rather than a classic two-column pivot.
 *
 * @property ProjectMemberRole $role
 */
class ProjectMember extends Pivot
{
    protected $table = 'project_members';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ProjectMemberRole::class,
        ];
    }
}
