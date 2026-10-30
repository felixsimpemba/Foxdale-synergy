<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Services\AuditLogger;
use App\Services\Auth\LoginService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor, private AuditLogger $audit) {}

    /**
     * Start TOTP setup. The client renders otpauth_url as a QR code.
     */
    public function enable(Request $request): JsonResponse
    {
        if ($request->user()->hasTwoFactorEnabled()) {
            throw new ApiException('Two-factor authentication is already enabled.', 'two_factor_already_enabled');
        }

        return response()->json($this->twoFactor->startSetup($request->user()));
    }

    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            throw new ApiException('Two-factor authentication is already enabled.', 'two_factor_already_enabled');
        }

        if (! $this->twoFactor->verifyCode($user, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => ['The code is invalid.']]);
        }

        $recoveryCodes = $this->twoFactor->confirmSetup($user);
        $this->audit->record('auth.two_factor_enabled', $user);

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    /**
     * Second login step: exchange the challenge token and a TOTP or recovery code for a token.
     */
    public function challenge(TwoFactorChallengeRequest $request, LoginService $login): JsonResponse
    {
        $challengeToken = $request->validated('challenge_token');
        $user = $this->twoFactor->findChallengeUser($challengeToken);

        if ($user === null || ! $user->is_active) {
            throw ValidationException::withMessages(['challenge_token' => ['The login challenge has expired. Sign in again.']]);
        }

        $passed = $request->filled('code')
            ? $this->twoFactor->verifyCode($user, $request->validated('code'))
            : $this->twoFactor->useRecoveryCode($user, $request->validated('recovery_code'));

        if (! $passed) {
            $this->audit->record('auth.two_factor_failed', $user, actor: $user);

            throw ValidationException::withMessages([
                $request->filled('code') ? 'code' : 'recovery_code' => ['The code is invalid.'],
            ]);
        }

        $this->twoFactor->clearChallenge($challengeToken);

        return response()->json($login->completeLogin($user, $request->validated('device_name')));
    }

    public function destroy(DisableTwoFactorRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->requiresTwoFactor()) {
            throw new ApiException('Two-factor authentication is mandatory for your role.', 'two_factor_required');
        }

        $this->twoFactor->disable($user);
        $this->audit->record('auth.two_factor_disabled', $user);

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }
}
