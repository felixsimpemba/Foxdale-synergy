<?php

namespace App\Services\Auth;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues Sanctum tokens. Session tokens (login, refresh) are prefixed "session:" and carry
 * every ability; personal tokens for scripts and MCP clients carry scoped abilities.
 */
class TokenService
{
    public const SESSION_PREFIX = 'session:';

    public function issueSessionToken(User $user, string $deviceName): NewAccessToken
    {
        $expiresAt = now()->addMinutes(config('incidents.auth.session_token_minutes'));

        return $user->createToken(self::SESSION_PREFIX.$deviceName, [TokenAbility::Full->value], $expiresAt);
    }

    /**
     * @param  list<string>  $abilities
     */
    public function issuePersonalToken(User $user, string $name, array $abilities, int $expiresInDays): NewAccessToken
    {
        return $user->createToken($name, array_values(array_unique($abilities)), now()->addDays($expiresInDays));
    }

    /**
     * The JSON body returned by login, 2FA challenge and refresh.
     *
     * @return array{token: string, token_type: string, expires_at: string|null, user: array<string, mixed>, two_factor_setup_required?: bool}
     */
    public function loginPayload(User $user, NewAccessToken $token): array
    {
        $user->loadMissing('roles', 'teams');

        $payload = [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at instanceof Carbon
                ? $token->accessToken->expires_at->toIso8601ZuluString()
                : null,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'teams' => $user->teams->pluck('id')->values()->all(),
            ],
        ];

        if ($user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            $payload['two_factor_setup_required'] = true;
        }

        return $payload;
    }
}
