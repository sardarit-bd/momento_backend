<?php

use App\Exceptions\TGC\TGCApiException;
use App\Exceptions\TGC\TGCAuthException;
use App\Exceptions\TGC\TGCFileUploadException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            \Illuminate\Support\Facades\Route::prefix('api')
                ->middleware('api')
                ->group(__DIR__.'/../routes/tgc.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => App\Http\Middleware\Authenticate::class,
            'roles' => App\Http\Middleware\RoleCheck::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/webhook/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            throw $e;
        });

        $exceptions->report(function (TGCAuthException|TGCApiException|TGCFileUploadException $e): void {
            Log::error('TGC exception', ['message' => $e->getMessage()]);
        });

        $exceptions->render(function (TGCAuthException $e, Request $request) {
            return response()->json(['message' => 'TGC authentication failed'], 503);
        });

        $exceptions->render(function (TGCApiException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 502);
        });

        $exceptions->render(function (TGCFileUploadException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
