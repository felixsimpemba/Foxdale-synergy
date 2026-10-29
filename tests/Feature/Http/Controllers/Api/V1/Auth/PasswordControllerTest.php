<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

describe('forgot', function () {
    it('sends a reset link pointing at the frontend', function () {
        Notification::fake();
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jane@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            return str_starts_with($notification->toMail($user)->actionUrl, 'http://localhost:3000/reset-password?token=');
        });
    });

    it('answers the same way for an unknown email', function () {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If the account exists, a reset link has been sent.');

        Notification::assertNothingSent();
    });
});

describe('reset', function () {
    it('sets the new password and revokes every token', function () {
        fakeBreachedPasswordCheck();
        $user = User::factory()->create();
        $user->createToken('session:web', ['*']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::broker('users')->createToken($user),
            'email' => $user->email,
            'password' => 'a-much-longer-password',
            'password_confirmation' => 'a-much-longer-password',
        ])->assertOk();

        expect(Hash::check('a-much-longer-password', $user->fresh()->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0);
    });

    it('returns 422 for an invalid token', function () {
        fakeBreachedPasswordCheck();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-much-longer-password',
            'password_confirmation' => 'a-much-longer-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    });

    it('rejects passwords shorter than 12 characters', function () {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::broker('users')->createToken($user),
            'email' => $user->email,
            'password' => 'short-pass',
            'password_confirmation' => 'short-pass',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'The password field must be at least 12 characters.');
    });

    it('rejects a password found in a known breach', function () {
        // The range API returns the SHA-1 suffixes (after the first 5 characters) of breached passwords.
        $suffix = strtoupper(substr(sha1('a-much-longer-password'), 5));
        fakeBreachedPasswordCheck("{$suffix}:42");
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::broker('users')->createToken($user),
            'email' => $user->email,
            'password' => 'a-much-longer-password',
            'password_confirmation' => 'a-much-longer-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    });
});

describe('accept invite', function () {
    it('sets the password with an invite token that is six days old', function () {
        fakeBreachedPasswordCheck();
        $user = User::factory()->create();
        $token = Password::broker('invites')->createToken($user);

        $this->travel(6)->days();

        $this->postJson('/api/v1/auth/accept-invite', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-much-longer-password',
            'password_confirmation' => 'a-much-longer-password',
        ])->assertOk();

        expect(Hash::check('a-much-longer-password', $user->fresh()->password))->toBeTrue();
    });
});

describe('update', function () {
    it('changes the password and revokes the user\'s other tokens only', function () {
        fakeBreachedPasswordCheck();
        $user = User::factory()->create();
        $current = $user->createToken('session:web', ['*']);
        $user->createToken('session:phone', ['*']);

        $this->withToken($current->plainTextToken)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'a-much-longer-password',
            'password_confirmation' => 'a-much-longer-password',
        ])->assertOk();

        expect(Hash::check('a-much-longer-password', $user->fresh()->password))->toBeTrue()
            ->and($user->tokens()->pluck('id')->all())->toBe([$current->accessToken->id]);
    });

    it('returns 422 when the current password is wrong', function () {
        $user = User::factory()->create();

        $this->withToken($user->createToken('session:web', ['*'])->plainTextToken)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'password' => 'a-much-longer-password',
            'password_confirmation' => 'a-much-longer-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    });
});
