<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Always answers the same way so the endpoint cannot be used to discover accounts.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('users')->sendResetLink($request->only('email'));

        return response()->json(['message' => 'If the account exists, a reset link has been sent.']);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        return $this->resetWithBroker($request, 'users', 'auth.password_reset');
    }

    public function acceptInvite(ResetPasswordRequest $request): JsonResponse
    {
        return $this->resetWithBroker($request, 'invites', 'auth.invite_accepted');
    }

    /**
     * Change the password of the signed-in user and sign out every other session.
     */
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        $this->audit->record('auth.password_changed', $user);

        return response()->json(['message' => 'Password updated.']);
    }

    private function resetWithBroker(ResetPasswordRequest $request, string $broker, string $auditAction): JsonResponse
    {
        $status = Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($auditAction): void {
                $user->update(['password' => $password]);
                $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
                $user->tokens()->delete();

                $this->audit->record($auditAction, $user, actor: $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __($status)]);
    }
}
