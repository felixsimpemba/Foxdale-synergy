<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CatalogSeeder::class,
            SlaPolicySeeder::class,
        ]);

        if (App::isLocal()) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@foxdale.local'],
                ['name' => 'Local Admin', 'password' => 'local-admin-password', 'timezone' => 'Africa/Lusaka'],
            );

            $admin->assignRole(RoleName::Admin->value);

            $this->call([ExampleServicesSeeder::class, RoutingExampleSeeder::class]);
        }
    }
}
