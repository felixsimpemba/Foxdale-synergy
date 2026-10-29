<?php

namespace Database\Seeders;

use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

/**
 * Default SLA targets from the specification. Existing rows (admin edits) are kept.
 */
class SlaPolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (config('incidents.sla.defaults') as $severity => $targets) {
            SlaPolicy::firstOrCreate(['severity' => $severity], [
                'ack_minutes' => $targets['ack'],
                'mitigate_minutes' => $targets['mitigate'],
                'resolve_minutes' => $targets['resolve'],
            ]);
        }
    }
}
