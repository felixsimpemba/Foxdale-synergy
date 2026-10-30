<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\TeamResource;
use App\Services\AuditLogger;
use App\Services\Auth\LoginService;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginService $login): JsonResponse
    {
        return response()->json($login->attempt(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name'),
        ));
    }

    public function logout(Request $request, AuditLogger $audit): Response
    {
        $audit->record('auth.logout', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Issue a fresh session token with the same abilities and revoke the current one.
     */
    public function refresh(Request $request, TokenService $tokens): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $deviceName = str($current->name)->after(TokenService::SESSION_PREFIX)->toString();

        $payload = $tokens->loginPayload($user, $tokens->issueSessionToken($user, $deviceName));
        $current->delete();

        return response()->json($payload);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'teams');

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'mattermost_username' => $user->mattermost_username,
            'timezone' => $user->timezone,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'two_factor_setup_required' => $user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled(),
            'roles' => $user->roles->pluck('name')->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            'teams' => TeamResource::collection($user->teams),
            'token_abilities' => $user->currentAccessToken()->abilities ?? [],
        ]]);
    }
}
