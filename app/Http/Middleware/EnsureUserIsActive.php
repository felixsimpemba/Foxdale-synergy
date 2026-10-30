<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivation revokes tokens, but this also rejects a token that slips through
 * (for example one issued while the deactivation was in flight).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->is_active) {
            throw new AuthenticationException('Your account has been deactivated.');
        }

        return $next($request);
    }
}
