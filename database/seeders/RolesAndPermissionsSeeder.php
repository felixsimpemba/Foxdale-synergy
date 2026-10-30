<?php

namespace Database\Seeders;

use App\Enums\PermissionKey;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission key and the five default roles. Safe to run in production
 * and to re-run: missing roles are created, new permission keys are granted to the default
 * roles that should hold them, and other admin changes to existing roles are kept. The admin
 * role is always reset to everything except incident deletion.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $added = [];

        foreach (PermissionKey::cases() as $permission) {
            if (! in_array($permission->value, $existing, true)) {
                Permission::create(['name' => $permission->value, 'guard_name' => 'web']);
                $added[] = $permission->value;
            }
        }

        foreach (RoleName::cases() as $roleName) {
            $defaults = array_map(fn (PermissionKey $key): string => $key->value, $roleName->defaultPermissions());
            $role = Role::where(['name' => $roleName->value, 'guard_name' => 'web'])->first();

            if ($role === null) {
                Role::create(['name' => $roleName->value, 'guard_name' => 'web'])->syncPermissions($defaults);

                continue;
            }

            // Keys added in a newer release go to the roles that hold them by default.
            $role->givePermissionTo(array_values(array_intersect($defaults, $added)));
        }

        // Admins always hold every permission except incident deletion, including keys added later.
        Role::findByName(RoleName::Admin->value, 'web')->syncPermissions(array_map(
            fn (PermissionKey $key): string => $key->value,
            RoleName::Admin->defaultPermissions(),
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
