<?php

namespace Database\Seeders;

use App\Enums\AssignmentMode;
use App\Enums\EscalationTargetType;
use App\Models\Category;
use App\Models\EscalationPolicy;
use App\Models\RoutingRule;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The example routing rules and escalation policy from specification section 5, for local
 * development. Production rules are set up by incident managers through the API.
 */
class RoutingExampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $policy = EscalationPolicy::firstOrCreate(['name' => 'Standard'], ['description' => 'On-call, then team lead, then incident managers']);

        if ($policy->levels()->doesntExist()) {
            $policy->levels()->createMany([
                ['level' => 1, 'after_minutes' => 0, 'target_type' => EscalationTargetType::OnCall],
                ['level' => 2, 'after_minutes' => 10, 'target_type' => EscalationTargetType::TeamLead],
                ['level' => 3, 'after_minutes' => 20, 'target_type' => EscalationTargetType::Role, 'target_id' => Role::findByName('incident_manager', 'web')->id],
            ]);
        }

        $category = fn (string $name): ?int => Category::firstWhere('name', $name)?->id;
        $team = fn (string $name): ?int => Team::firstWhere('name', $name)?->id;

        $rules = [
            ['name' => 'Database issues to DBA', 'priority' => 20, 'category_id' => $category('Database'), 'team_id' => $team('DBA'), 'assignment_mode' => AssignmentMode::OnCall],
            ['name' => 'Network SEV1-3 to Infrastructure', 'priority' => 30, 'category_id' => $category('Network'), 'max_severity' => 3, 'team_id' => $team('Infrastructure'), 'assignment_mode' => AssignmentMode::OnCall],
            ['name' => 'Security to Security team', 'priority' => 40, 'category_id' => $category('Security'), 'team_id' => $team('Security'), 'assignment_mode' => AssignmentMode::OnCall],
            ['name' => 'Minor application issues to the owning team', 'priority' => 50, 'category_id' => $category('Application'), 'min_severity' => 3, 'max_severity' => 4, 'team_id' => null, 'assignment_mode' => AssignmentMode::RoundRobin],
            ['name' => 'Everything else to NOC', 'priority' => 99, 'team_id' => $team('NOC'), 'assignment_mode' => AssignmentMode::LeastLoaded],
        ];

        foreach ($rules as $rule) {
            RoutingRule::firstOrCreate(['name' => $rule['name']], [...$rule, 'escalation_policy_id' => $policy->id]);
        }
    }
}
