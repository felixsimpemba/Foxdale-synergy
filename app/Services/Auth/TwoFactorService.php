<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication: setup secrets, code checks with replay protection,
 * recovery codes, and the short-lived challenge issued after a correct password.
 */
class TwoFactorService
{
    public function __construct(private Google2FA $google2fa) {}

    /**
     * @return array{secret: string, otpauth_url: string}
     */
    public function startSetup(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret),
        ];
    }

    /**
     * @return list<string> the plain recovery codes, shown to the user once
     */
    public function confirmSetup(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Check a TOTP code. A code that was accepted once cannot be used again.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        $code = preg_replace('/\s+/', '', $code);

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $code, 1)) {
            return false;
        }

        // Codes are valid for up to 90 seconds with a window of 1; block reuse for that long.
        return Cache::add("two-factor-used:{$user->id}:{$code}", true, 90);
    }

    /**
     * Check and consume a recovery code.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $index = array_search(trim($code), $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    public function createChallenge(User $user): string
    {
        $token = 'c_'.Str::random(40);

        Cache::put($this->challengeKey($token), $user->id, now()->addMinutes(config('incidents.auth.two_factor_challenge_minutes')));

        return $token;
    }

    public function findChallengeUser(string $challengeToken): ?User
    {
        $userId = Cache::get($this->challengeKey($challengeToken));

        return $userId === null ? null : User::find($userId);
    }

    public function clearChallenge(string $challengeToken): void
    {
        Cache::forget($this->challengeKey($challengeToken));
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, config('incidents.auth.two_factor_recovery_codes')),
        );
    }

    private function challengeKey(string $token): string
    {
        return 'two-factor-challenge:'.hash('sha256', $token);
    }
}
