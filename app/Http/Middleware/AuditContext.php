<?php

namespace App\Http\Middleware;

use App\Enums\AuditSource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records where a request came from so the audit log (and queued jobs it dispatches)
 * know the source, IP address and user agent.
 */
class AuditContext
{
    public function handle(Request $request, Closure $next, string $source = 'api'): Response
    {
        Context::add('audit.source', AuditSource::from($source)->value);
        Context::addHidden('audit.ip', $request->ip());
        Context::addHidden('audit.user_agent', $request->userAgent());

        return $next($request);
    }
}
