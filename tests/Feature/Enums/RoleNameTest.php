<?php

use App\Enums\PermissionKey;
use App\Enums\RoleName;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
| The permission matrix from specification section 4, written out independently of
| RoleName::defaultPermissions() so a change to either one fails this test.
| "Own only", "Team only" and "Assigned only" grant the permission; policies scope it.
*/
dataset('permission matrix', [
    'reporter' => [RoleName::Reporter, [
        'incident.create', 'incident.view.own', 'incident.update', 'document.upload',
        'project.view', 'task.create', 'task.update',
    ]],
    'responder' => [RoleName::Responder, [
        'incident.create', 'incident.view.own', 'incident.view.team', 'incident.update',
        'incident.acknowledge', 'incident.transition', 'task.assign', 'task.review',
        'project.view', 'task.create', 'task.update', 'time.log', 'document.upload',
        'resolution.write', 'rca.write', 'dashboard.view', 'report.view', 'ai.use',
    ]],
    'noc' => [RoleName::Noc, [
        'incident.create', 'incident.view.own', 'incident.view.team', 'incident.view.all',
        'incident.update', 'incident.acknowledge', 'incident.transition', 'incident.severity',
        'incident.assign', 'task.assign', 'task.review', 'project.view', 'task.create',
        'task.update', 'time.log', 'document.upload', 'resolution.write',
        'dashboard.view', 'report.view', 'ai.use',
    ]],
    'incident manager' => [RoleName::IncidentManager, [
        'incident.create', 'incident.view.own', 'incident.view.team', 'incident.view.all',
        'incident.update', 'incident.acknowledge', 'incident.transition', 'incident.severity',
        'incident.assign', 'incident.delete', 'incident.close', 'task.assign', 'task.review',
        'task.delete', 'project.view', 'project.manage', 'task.create', 'task.update',
        'time.log', 'document.upload',
        'document.delete', 'knowledge.publish', 'resolution.write', 'rca.write', 'rca.approve',
        'dashboard.view', 'report.view', 'report.export', 'report.schedule', 'ai.use', 'routing.manage',
    ]],
    'admin' => [RoleName::Admin, [
        'incident.create', 'incident.view.own', 'incident.view.team', 'incident.view.all',
        'incident.update', 'incident.acknowledge', 'incident.transition', 'incident.severity',
        'incident.assign', 'incident.close', 'project.view', 'project.manage', 'task.create',
        'task.update', 'task.assign', 'task.review', 'task.delete',
        'time.log', 'document.upload', 'document.delete',
        'knowledge.publish', 'resolution.write',
        'rca.write', 'rca.approve', 'dashboard.view', 'report.view', 'report.export',
        'report.schedule', 'ai.use', 'routing.manage', 'user.manage', 'integration.manage',
        'audit.view',
    ]],
]);

test('each seeded role grants exactly the permissions in the matrix', function (RoleName $role, array $granted) {
    $user = userWithRole($role);

    foreach (PermissionKey::cases() as $permission) {
        expect($user->can($permission->value))->toBe(
            in_array($permission->value, $granted, true),
            "{$role->value} / {$permission->value}",
        );
    }
})->with('permission matrix');

test('admins cannot delete incidents', function () {
    expect(userWithRole(RoleName::Admin)->can(PermissionKey::IncidentDelete->value))->toBeFalse();
});

test('2FA is mandatory for admins and incident managers only', function () {
    $mandatory = array_values(array_filter(RoleName::cases(), fn (RoleName $role) => $role->requiresTwoFactor()));

    expect($mandatory)->toBe([RoleName::IncidentManager, RoleName::Admin]);
});

test('re-seeding grants newly added permission keys to the default roles', function () {
    Permission::findByName('document.delete', 'web')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(userWithRole(RoleName::IncidentManager)->can('document.delete'))->toBeTrue()
        ->and(userWithRole(RoleName::Noc)->can('document.delete'))->toBeFalse();
});
