<?php

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    it('lists users filtered by role', function () {
        actingAsRole(RoleName::Admin);
        $responder = userWithRole(RoleName::Responder, ['name' => 'Ruth Responder']);
        userWithRole(RoleName::Reporter);

        $this->getJson('/api/v1/admin/users?role=responder')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $responder->id)
            ->assertJsonPath('data.0.roles', ['responder'])
            ->assertJsonStructure(['data', 'links', 'meta']);
    });

    it('searches by name or email', function () {
        actingAsRole(RoleName::Admin);
        $match = User::factory()->create(['email' => 'zed@payments.example.com']);
        User::factory()->create(['email' => 'other@example.com']);

        $this->getJson('/api/v1/admin/users?q=payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    });

    it('caps per_page at 100', function () {
        actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/admin/users?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
    });

    it('returns 403 for an incident manager', function () {
        actingAsRole(RoleName::IncidentManager);

        $this->getJson('/api/v1/admin/users')->assertForbidden()->assertJsonPath('code', 'forbidden');
    });

    it('returns 403 two_factor_setup_required for an admin without 2FA', function () {
        Sanctum::actingAs(User::factory()->withRole(RoleName::Admin)->create(), ['*']);

        $this->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_setup_required');
    });

    it('returns 403 for an admin using a personal token', function () {
        Sanctum::actingAs(userWithRole(RoleName::Admin), ['mcp:read', 'mcp:write']);

        $this->getJson('/api/v1/admin/users')->assertForbidden();
    });
});

describe('store', function () {
    it('creates the user with roles and teams and emails an invitation', function () {
        Notification::fake();
        actingAsRole(RoleName::Admin);
        $team = Team::factory()->create();

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Jane Banda',
            'email' => 'jane@example.com',
            'phone' => '+260971234567',
            'mattermost_username' => 'jane.banda',
            'timezone' => 'Africa/Lusaka',
            'roles' => ['responder'],
            'team_ids' => [$team->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.roles', ['responder'])
            ->assertJsonPath('data.teams.0.id', $team->id)
            ->assertJsonPath('data.is_active', true);

        $user = User::firstWhere('email', 'jane@example.com');
        expect($user->hasRole('responder'))->toBeTrue();
        Notification::assertSentTo($user, UserInvited::class, function (UserInvited $notification) use ($user) {
            return str_contains($notification->toMail($user)->actionUrl, '/accept-invite?token=');
        });
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.created', 'entity_type' => 'user', 'entity_id' => $user->id]);
    });

    it('returns 422 with every required field', function () {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/admin/users', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'roles']);
    });

    it('rejects a phone number that is not E.164', function () {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Jane', 'email' => 'jane@example.com', 'phone' => '0971234567', 'roles' => ['reporter'],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'The phone number must be in E.164 format, for example +260971234567.');
    });

    it('rejects an unknown role', function () {
        actingAsRole(RoleName::Admin);

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Jane', 'email' => 'jane@example.com', 'roles' => ['superuser'],
        ])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    });
});

describe('update', function () {
    it('updates the profile', function () {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->create();

        $this->putJson("/api/v1/admin/users/{$user->id}", ['mattermost_username' => 'new.name', 'timezone' => 'Europe/London'])
            ->assertOk()
            ->assertJsonPath('data.mattermost_username', 'new.name');

        expect($user->fresh()->timezone)->toBe('Europe/London');
    });

    it('rejects an email already used by another user', function () {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->putJson("/api/v1/admin/users/{$user->id}", ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });
});

describe('roles', function () {
    it('replaces the user\'s roles and audits the change', function () {
        actingAsRole(RoleName::Admin);
        $user = userWithRole(RoleName::Reporter);

        $this->postJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['responder', 'noc']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['responder', 'noc']);

        $log = AuditLog::firstWhere('action', 'user.roles_changed');
        expect($log->changes)->toEqual(['before' => ['reporter'], 'after' => ['responder', 'noc']]);
    });

    it('returns 422 last_admin when removing admin from the only admin', function () {
        $admin = actingAsRole(RoleName::Admin);

        $this->postJson("/api/v1/admin/users/{$admin->id}/roles", ['roles' => ['responder']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'last_admin');

        expect($admin->fresh()->hasRole('admin'))->toBeTrue();
    });
});

describe('activation', function () {
    it('deactivates a user and revokes every token', function () {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->create();
        $user->createToken('session:web', ['*']);

        $this->postJson("/api/v1/admin/users/{$user->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect($user->tokens()->count())->toBe(0);
    });

    it('reactivates a user', function () {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->inactive()->create();

        $this->postJson("/api/v1/admin/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    });

    it('returns 403 when an admin deactivates themselves', function () {
        $admin = actingAsRole(RoleName::Admin);

        $this->postJson("/api/v1/admin/users/{$admin->id}/deactivate")->assertForbidden();

        expect($admin->fresh()->is_active)->toBeTrue();
    });
});

describe('resend invite', function () {
    it('emails a new invitation to someone who has not signed in yet', function () {
        Notification::fake();
        actingAsRole(RoleName::Admin);
        $user = User::factory()->create(['last_login_at' => null]);

        $this->postJson("/api/v1/admin/users/{$user->id}/resend-invite")->assertOk();

        Notification::assertSentTo($user, UserInvited::class);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.invite_resent', 'entity_id' => $user->id]);
    });

    it('returns 422 already_signed_in once the person has signed in', function () {
        Notification::fake();
        actingAsRole(RoleName::Admin);
        $user = User::factory()->create(['last_login_at' => now()]);

        $this->postJson("/api/v1/admin/users/{$user->id}/resend-invite")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'already_signed_in');

        Notification::assertNothingSent();
    });

    it('returns 422 user_inactive for a deactivated account', function () {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->create(['last_login_at' => null]);
        $user->forceFill(['is_active' => false])->save();

        $this->postJson("/api/v1/admin/users/{$user->id}/resend-invite")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'user_inactive');
    });

    it('returns 403 without user.manage', function () {
        actingAsRole(RoleName::IncidentManager);

        $this->postJson('/api/v1/admin/users/'.User::factory()->create()->id.'/resend-invite')->assertForbidden();
    });
});

describe('reset two-factor', function () {
    it('removes 2FA and signs the person out everywhere', function () {
        actingAsRole(RoleName::Admin);
        $user = userWithRole(RoleName::IncidentManager);
        $user->createToken('session:web');

        $this->deleteJson("/api/v1/admin/users/{$user->id}/two-factor")
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false);

        expect($user->fresh()->two_factor_secret)->toBeNull()
            ->and($user->tokens()->count())->toBe(0);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.two_factor_reset', 'entity_id' => $user->id]);
    });

    it('returns 422 when the person has no 2FA', function () {
        actingAsRole(RoleName::Admin);
        $user = User::factory()->create();

        $this->deleteJson("/api/v1/admin/users/{$user->id}/two-factor")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'two_factor_not_enabled');
    });

    it('does not let admins reset their own 2FA this way', function () {
        $admin = actingAsRole(RoleName::Admin);

        $this->deleteJson("/api/v1/admin/users/{$admin->id}/two-factor")->assertForbidden();
    });
});
