<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreTokenRequest;
use App\Http\Resources\PersonalAccessTokenResource;
use App\Services\AuditLogger;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Personal access tokens for scripts and MCP clients.
 */
class TokenController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PersonalAccessTokenResource::collection(
            $request->user()->tokens()->orderByDesc('created_at')->orderByDesc('id')->get(),
        );
    }

    public function store(StoreTokenRequest $request, TokenService $tokens): JsonResponse
    {
        $token = $tokens->issuePersonalToken(
            $request->user(),
            $request->validated('name'),
            $request->validated('abilities'),
            $request->validated('expires_in_days') ?? config('incidents.auth.personal_token_default_days'),
        );

        $this->audit->record('token.created', changes: [
            'token_id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'abilities' => $token->accessToken->abilities,
        ]);

        return (new PersonalAccessTokenResource($token->accessToken))
            ->additional(['token' => $token->plainTextToken])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, int $tokenId): Response
    {
        $token = $request->user()->tokens()->findOrFail($tokenId);
        $token->delete();

        $this->audit->record('token.revoked', changes: ['token_id' => $tokenId, 'name' => $token->name]);

        return response()->noContent();
    }
}
