<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins and incident managers must finish TOTP setup before using anything other than
 * the account and two-factor setup endpoints.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasTwoFactorEnabled() && $user->requiresTwoFactor()) {
            throw new ApiException(
                'Two-factor authentication must be set up for your role before continuing.',
                'two_factor_setup_required',
                403,
            );
        }

        return $next($request);
    }
}
