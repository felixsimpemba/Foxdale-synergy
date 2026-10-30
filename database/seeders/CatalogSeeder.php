<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Team;
use Illuminate\Database\Seeder;

/**
 * Default issue categories and teams from the specification.
 */
class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = ['Infrastructure', 'Application', 'Database', 'Network', 'Security', 'Third-party', 'Access'];

        foreach ($categories as $name) {
            Category::firstOrCreate(['name' => $name, 'parent_id' => null]);
        }

        $teams = [
            'NOC' => 'Network operations centre; default route for unmatched incidents',
            'Payments' => 'Payment gateway and transaction processing',
            'Infrastructure' => 'Servers, networking and cloud platform',
            'DBA' => 'Database administration',
            'Security' => 'Security operations and access control',
        ];

        foreach ($teams as $name => $description) {
            Team::firstOrCreate(['name' => $name], ['description' => $description]);
        }
    }
}
