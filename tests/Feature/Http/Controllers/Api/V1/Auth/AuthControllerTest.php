<?php

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

describe('login', function () {
    it('returns a bearer token that expires after 8 hours', function () {
        $this->freezeTime();
        $user = userWithRole(RoleName::Responder, ['email' => 'jane@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
            'device_name' => 'web',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', now()->addHours(8)->toIso8601ZuluString())
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.roles', ['responder'])
            ->assertJsonMissingPath('two_factor_setup_required');

        expect(PersonalAccessToken::findToken($response->json('token'))->tokenable->is($user))->toBeTrue()
            ->and($user->fresh()->last_login_at)->not->toBeNull();
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'auth.login', 'user_id' => $user->id, 'source' => 'api']);
    });

    it('returns 422 for a wrong password and records the failure', function () {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'wrong', 'device_name' => 'web'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'auth.login_failed', 'entity_id' => $user->id]);
    });

    it('returns 422 with every missing field', function () {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    });

    it('returns 429 account_locked after five failed attempts within 15 minutes', function () {
        User::factory()->create(['email' => 'jane@example.com']);

        foreach (range(1, 5) as $attempt) {
            // Different IPs, so the per-IP route throttle does not trigger first.
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$attempt}"])
                ->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'wrong', 'device_name' => 'web'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'password', 'device_name' => 'web'])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'account_locked')
            ->assertHeader('Retry-After');
    });

    it('returns 403 for a deactivated account with the right password', function () {
        User::factory()->inactive()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'password', 'device_name' => 'web'])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    });

    it('returns a 2FA challenge instead of a token when 2FA is enabled', function () {
        User::factory()->withTwoFactor()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'password', 'device_name' => 'web'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['challenge_token'])
            ->assertJsonMissingPath('token');

        expect(PersonalAccessToken::count())->toBe(0);
    });

    it('flags two_factor_setup_required for an admin without 2FA', function () {
        User::factory()->withRole(RoleName::Admin)->create(['email' => 'admin@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'password', 'device_name' => 'web'])
            ->assertOk()
            ->assertJsonPath('two_factor_setup_required', true);
    });
});

describe('logout', function () {
    it('revokes the current token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('session:web', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        expect($user->tokens()->count())->toBe(0);
    });
});

describe('refresh', function () {
    it('issues a new session token and revokes the old one', function () {
        $user = User::factory()->create();
        $old = $user->createToken('session:web', ['*']);

        $response = $this->withToken($old->plainTextToken)->postJson('/api/v1/auth/refresh');

        $response->assertOk()->assertJsonPath('user.id', $user->id);
        expect(PersonalAccessToken::find($old->accessToken->id))->toBeNull()
            ->and(PersonalAccessToken::findToken($response->json('token'))->name)->toBe('session:web');
    });

    it('returns 403 for a personal token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('script', ['mcp:read'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/refresh')->assertForbidden();
    });
});

describe('me', function () {
    it('returns the user with roles, permissions and teams', function () {
        $user = actingAsRole(RoleName::Reporter);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles', ['reporter'])
            ->assertJsonPath('data.permissions', [
                'document.upload', 'incident.create', 'incident.update', 'incident.view.own',
                'project.view', 'task.create', 'task.update',
            ]);
    });

    it('returns 401 in the standard error format without a token', function () {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);
    });

    it('returns 401 for an expired token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('session:web', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    });

    it('returns 401 for a deactivated user whose token was not revoked', function () {
        $user = User::factory()->inactive()->create();
        $token = $user->createToken('session:web', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    });
});
