<?php

namespace Database\Seeders;

use App\Enums\ServiceCriticality;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Seeder;

/**
 * A starter service catalog for local development, owned by the default teams. Production
 * services are added by admins in the app (Catalog > Services).
 */
class ExampleServicesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $services = [
            ['Payment gateway', 'Card and bank payment processing, callbacks and settlement', 'Payments', ServiceCriticality::Critical],
            ['Mobile money integration', 'Airtel Money and MTN MoMo collections and disbursements', 'Payments', ServiceCriticality::Critical],
            ['Customer portal', 'Self-service web portal and public API', 'Payments', ServiceCriticality::High],
            ['Core database cluster', 'Primary MySQL cluster and replicas', 'DBA', ServiceCriticality::Critical],
            ['Reporting and BI', 'Data warehouse, scheduled reports and dashboards', 'DBA', ServiceCriticality::Low],
            ['Network and VPN', 'Office network, site-to-site VPN and firewalls', 'Infrastructure', ServiceCriticality::High],
            ['Email and notifications', 'Outbound email, SMS and push notifications', 'Infrastructure', ServiceCriticality::Medium],
            ['Identity and SSO', 'Single sign-on, MFA and directory', 'Security', ServiceCriticality::High],
            ['Monitoring and logging', 'Metrics, log aggregation and alerting', 'NOC', ServiceCriticality::Medium],
        ];

        foreach ($services as [$name, $description, $team, $criticality]) {
            Service::firstOrCreate(['name' => $name], [
                'description' => $description,
                'owner_team_id' => Team::firstWhere('name', $team)?->id,
                'criticality' => $criticality,
                'is_active' => true,
            ]);
        }
    }
}
