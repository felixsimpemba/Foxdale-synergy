<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\TokenService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signed-in browser sessions. Personal access tokens for scripts and MCP clients are
 * managed separately through /auth/tokens.
 */
class SessionController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SessionResource::collection(
            $request->user()->tokens()
                ->where('name', 'like', TokenService::SESSION_PREFIX.'%')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    /**
     * Sign out one other device. The current session is signed out with POST /auth/logout.
     */
    public function destroy(Request $request, int $tokenId): Response
    {
        $token = $this->sessionQuery($request)->findOrFail($tokenId);

        if ($token->getKey() === $this->currentTokenId($request)) {
            throw new ApiException('This is your current session. Use sign out instead.', 'current_session');
        }

        $token->delete();
        $this->audit->record('session.revoked', changes: ['token_id' => $tokenId, 'name' => $token->name]);

        return response()->noContent();
    }

    /**
     * Sign out every other device, keeping the session making the request.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $currentId = $this->currentTokenId($request);

        $revoked = $this->sessionQuery($request)
            ->when($currentId !== null, fn ($query) => $query->whereKeyNot($currentId))
            ->delete();

        $this->audit->record('session.revoked_others', changes: ['tokens_revoked' => $revoked]);

        return response()->json(['revoked' => $revoked]);
    }

    /**
     * @return MorphMany<PersonalAccessToken, User>
     */
    private function sessionQuery(Request $request): MorphMany
    {
        return $request->user()->tokens()->where('name', 'like', TokenService::SESSION_PREFIX.'%');
    }

    private function currentTokenId(Request $request): int|string|null
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->getKey() : null;
    }
}
