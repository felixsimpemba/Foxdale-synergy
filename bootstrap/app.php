<?php

use App\Http\Middleware\AuditContext;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [AuditContext::class]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'active' => EnsureUserIsActive::class,
            'idempotent' => EnsureIdempotency::class,
            'two-factor' => EnsureTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Standard error format: { "message", "code", "errors" } (specification section 15).
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Validation failed',
                'code' => 'validation_failed',
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'Unauthenticated.',
                'code' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$code, $defaultMessage] = match ($e->getStatusCode()) {
                401 => ['unauthenticated', 'Unauthenticated.'],
                403 => ['forbidden', 'This action is unauthorized.'],
                404 => ['not_found', 'Resource not found.'],
                405 => ['method_not_allowed', 'Method not allowed.'],
                409 => ['conflict', 'The resource changed since it was last read.'],
                429 => ['throttled', 'Too many requests.'],
                503 => ['service_unavailable', 'Service unavailable.'],
                default => ['http_error', 'Request failed.'],
            };

            // Never leak model class names from "No query results for model [...]".
            $message = $e->getStatusCode() === 404 ? $defaultMessage : ($e->getMessage() ?: $defaultMessage);

            return response()->json(['message' => $message, 'code' => $code], $e->getStatusCode(), $e->getHeaders());
        });
    })->create();
