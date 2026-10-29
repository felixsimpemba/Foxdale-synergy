<?php

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

describe('enable and confirm', function () {
    it('returns a secret and otpauth URL, then recovery codes once a valid code is confirmed', function () {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        Sanctum::actingAs($user, ['*']);

        $setup = $this->postJson('/api/v1/auth/two-factor/enable')
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url']);

        expect($setup->json('otpauth_url'))->toContain('jane%40example.com');

        $this->postJson('/api/v1/auth/two-factor/confirm', [
            'code' => (new Google2FA)->getCurrentOtp($setup->json('secret')),
        ])->assertOk()->assertJsonCount(8, 'recovery_codes');

        expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'auth.two_factor_enabled', 'user_id' => $user->id]);
    });

    it('returns 422 when the confirmation code is wrong', function () {
        Sanctum::actingAs($user = User::factory()->create(), ['*']);
        $this->postJson('/api/v1/auth/two-factor/enable')->assertOk();

        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'The code is invalid.');

        expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    });

    it('returns 422 when 2FA is already enabled', function () {
        Sanctum::actingAs(User::factory()->withTwoFactor()->create(), ['*']);

        $this->postJson('/api/v1/auth/two-factor/enable')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'two_factor_already_enabled');
    });

    it('lets an admin without 2FA reach the setup endpoints', function () {
        Sanctum::actingAs(User::factory()->withRole(RoleName::Admin)->create(), ['*']);

        $this->postJson('/api/v1/auth/two-factor/enable')->assertOk();
    });
});

describe('challenge', function () {
    function startChallenge(User $user): string
    {
        return test()->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_name' => 'web',
        ])->json('challenge_token');
    }

    it('issues a token for a valid TOTP code', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = startChallenge($user);

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $challenge, 'code' => currentTotpCode($user), 'device_name' => 'web',
        ])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonStructure(['token', 'expires_at']);
    });

    it('rejects a TOTP code that was already used', function () {
        $user = User::factory()->withTwoFactor()->create();
        $code = currentTotpCode($user);

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => startChallenge($user), 'code' => $code, 'device_name' => 'web',
        ])->assertOk();

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => startChallenge($user), 'code' => $code, 'device_name' => 'web',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    });

    it('accepts a recovery code once', function () {
        $user = User::factory()->withTwoFactor()->create();

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => startChallenge($user), 'recovery_code' => 'recovery-code-one', 'device_name' => 'web',
        ])->assertOk();

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => startChallenge($user), 'recovery_code' => 'recovery-code-one', 'device_name' => 'web',
        ])->assertUnprocessable()->assertJsonValidationErrors('recovery_code');

        expect($user->fresh()->two_factor_recovery_codes)->toBe(['recovery-code-two']);
    });

    it('returns 422 for an expired challenge', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = startChallenge($user);

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $challenge, 'code' => currentTotpCode($user), 'device_name' => 'web',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    });

    it('cannot be reused after a successful login', function () {
        $user = User::factory()->withTwoFactor()->create();
        $challenge = startChallenge($user);

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $challenge, 'recovery_code' => 'recovery-code-one', 'device_name' => 'web',
        ])->assertOk();

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_token' => $challenge, 'recovery_code' => 'recovery-code-two', 'device_name' => 'web',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    });
});

describe('disable', function () {
    it('turns 2FA off when the password is correct', function () {
        Sanctum::actingAs($user = User::factory()->withTwoFactor()->create(), ['*']);

        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'password'])->assertOk();

        expect($user->fresh())
            ->hasTwoFactorEnabled()->toBeFalse()
            ->two_factor_secret->toBeNull();
    });

    it('returns 422 for a wrong password', function () {
        Sanctum::actingAs(User::factory()->withTwoFactor()->create(), ['*']);

        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    });

    it('returns 422 two_factor_required for roles where 2FA is mandatory', function (RoleName $role) {
        actingAsRole($role);

        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'two_factor_required');
    })->with([RoleName::Admin, RoleName::IncidentManager]);
});
