<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Monitoring source API keys. The plain key (and signing secret) is in the response to
 * store and rotate only; it cannot be retrieved again.
 */
class ApiKeyController extends Controller
{
    public function __construct(private ApiKeyService $apiKeys) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ApiKey::class);

        return ApiKeyResource::collection(
            ApiKey::query()
                ->when(! $request->boolean('include_revoked'), fn ($query) => $query->active())
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        Gate::authorize('create', ApiKey::class);

        $created = $this->apiKeys->create($request->validated(), $request->user());

        return $this->withSecrets($created)->setStatusCode(201);
    }

    public function rotate(ApiKey $apiKey): JsonResponse
    {
        Gate::authorize('rotate', $apiKey);

        return $this->withSecrets($this->apiKeys->rotate($apiKey));
    }

    public function destroy(ApiKey $apiKey): Response
    {
        Gate::authorize('delete', $apiKey);

        $this->apiKeys->revoke($apiKey);

        return response()->noContent();
    }

    /**
     * @param  array{api_key: ApiKey, plain_key: string, signing_secret: string|null}  $result
     */
    private function withSecrets(array $result): JsonResponse
    {
        return (new ApiKeyResource($result['api_key']))
            ->additional(array_filter([
                'key' => $result['plain_key'],
                'signing_secret' => $result['signing_secret'],
            ]))
            ->response();
    }
}
