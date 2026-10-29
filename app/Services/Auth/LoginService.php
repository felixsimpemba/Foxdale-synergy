<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password login with account lockout. Returns either a token payload or a 2FA challenge.
 */
class LoginService
{
    public function __construct(
        private TokenService $tokens,
        private TwoFactorService $twoFactor,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function attempt(string $email, string $password, string $deviceName): array
    {
        $lockKey = 'login-lockout:'.Str::lower($email);

        if (RateLimiter::tooManyAttempts($lockKey, config('incidents.auth.lockout_attempts'))) {
            $seconds = RateLimiter::availableIn($lockKey);

            throw new ApiException(
                "Too many failed logins. Try again in {$seconds} seconds.",
                'account_locked',
                429,
                headers: ['Retry-After' => $seconds],
            );
        }

        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($lockKey, config('incidents.auth.lockout_minutes') * 60);
            $this->audit->record('auth.login_failed', $user, ['email' => $email], actor: $user);

            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        RateLimiter::clear($lockKey);

        if (! $user->is_active) {
            throw new ApiException('This account has been deactivated.', 'account_inactive', 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            return [
                'two_factor_required' => true,
                'challenge_token' => $this->twoFactor->createChallenge($user),
            ];
        }

        return $this->completeLogin($user, $deviceName);
    }

    /**
     * @return array<string, mixed>
     */
    public function completeLogin(User $user, string $deviceName): array
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, ['device_name' => $deviceName], actor: $user);

        return $this->tokens->loginPayload($user, $this->tokens->issueSessionToken($user, $deviceName));
    }
}
