<?php

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('lists roles with permissions and marks the default ones', function () {
    actingAsRole(RoleName::Admin);

    $this->getJson('/api/v1/admin/roles')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.name', 'reporter')
        ->assertJsonPath('data.0.is_system', true)
        ->assertJsonPath('data.0.permissions', [
            'document.upload', 'incident.create', 'incident.update', 'incident.view.own',
            'project.view', 'task.create', 'task.update',
        ]);
});

it('lists every permission key with a description', function () {
    actingAsRole(RoleName::Admin);

    $this->getJson('/api/v1/admin/permissions')
        ->assertOk()
        ->assertJsonCount(34, 'data')
        ->assertJsonPath('data.0', ['key' => 'incident.create', 'description' => 'Report incidents']);
});

it('creates a custom role whose permissions apply to its users', function () {
    actingAsRole(RoleName::Admin);

    $this->postJson('/api/v1/admin/roles', ['name' => 'auditor', 'permissions' => ['audit.view', 'report.view']])
        ->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.permissions', ['audit.view', 'report.view']);

    $auditor = User::factory()->create()->assignRole('auditor');
    expect($auditor->can('audit.view'))->toBeTrue()
        ->and($auditor->can('user.manage'))->toBeFalse();
    $this->assertDatabaseHas(AuditLog::class, ['action' => 'role.created', 'entity_type' => 'role']);
});

it('rejects an unknown permission key', function () {
    actingAsRole(RoleName::Admin);

    $this->postJson('/api/v1/admin/roles', ['name' => 'auditor', 'permissions' => ['everything']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permissions.0');
});

it('changes the permissions of a default role', function () {
    actingAsRole(RoleName::Admin);
    $reporter = Role::findByName('reporter', 'web');

    $this->putJson("/api/v1/admin/roles/{$reporter->id}", ['permissions' => ['incident.create', 'incident.view.own']])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['incident.create', 'incident.view.own']);

    expect(userWithRole(RoleName::Reporter)->can('document.upload'))->toBeFalse();
});

it('returns 422 protected_role when editing the admin role', function () {
    actingAsRole(RoleName::Admin);
    $admin = Role::findByName('admin', 'web');

    $this->putJson("/api/v1/admin/roles/{$admin->id}", ['permissions' => ['incident.create']])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'protected_role');
});

describe('destroy', function () {
    it('deletes an unused custom role', function () {
        actingAsRole(RoleName::Admin);
        $role = Role::create(['name' => 'auditor', 'guard_name' => 'web']);

        $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertNoContent();

        expect(Role::where('name', 'auditor')->exists())->toBeFalse();
    });

    it('returns 422 protected_role for a default role', function () {
        actingAsRole(RoleName::Admin);

        $this->deleteJson('/api/v1/admin/roles/'.Role::findByName('reporter', 'web')->id)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'protected_role');
    });

    it('returns 422 role_in_use while users hold the role', function () {
        actingAsRole(RoleName::Admin);
        $role = Role::create(['name' => 'auditor', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        $this->deleteJson("/api/v1/admin/roles/{$role->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'role_in_use');
    });
});

it('returns 403 for an incident manager', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->getJson('/api/v1/admin/roles')->assertForbidden();
});
