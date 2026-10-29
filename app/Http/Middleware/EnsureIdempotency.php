<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honours the Idempotency-Key header on POST requests: a retry with the same key and body
 * gets the stored response instead of creating a second record. Keys are scoped to the
 * caller and the path, and kept for 24 hours.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (blank($key) || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            throw new ApiException('The Idempotency-Key header may not exceed 255 characters.', 'validation_failed');
        }

        $caller = $request->user()?->getAuthIdentifier() ?? $request->ip();
        $cacheKey = 'idempotency:'.hash('sha256', $caller.'|'.$request->path().'|'.$key);
        $fingerprint = hash('sha256', $request->getContent());

        try {
            return Cache::lock($cacheKey.':lock', 30)->block(10, function () use ($request, $next, $cacheKey, $fingerprint): Response {
                $stored = Cache::get($cacheKey);

                if ($stored !== null) {
                    if ($stored['fingerprint'] !== $fingerprint) {
                        throw new ApiException('This Idempotency-Key was already used for a different request.', 'idempotency_key_reused');
                    }

                    return response($stored['body'], $stored['status'], [
                        'Content-Type' => 'application/json',
                        'Idempotent-Replayed' => 'true',
                    ]);
                }

                $response = $next($request);

                if ($response->isSuccessful()) {
                    Cache::put($cacheKey, [
                        'fingerprint' => $fingerprint,
                        'status' => $response->getStatusCode(),
                        'body' => $response->getContent(),
                    ], now()->addDay());
                }

                return $response;
            });
        } catch (LockTimeoutException) {
            throw new ApiException('A request with this Idempotency-Key is still being processed.', 'conflict', 409);
        }
    }
}
