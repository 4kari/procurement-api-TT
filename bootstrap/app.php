<?php

use App\Http\Middleware\RequireRole;
use App\Http\Middleware\TokenAuthenticate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*');
        });
        // Always return JSON for API routes
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal.',
                    'errors'  => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Unauthenticated.',
                ], 401);
            }

            if ($e instanceof AccessDeniedHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Forbidden.',
                ], 403);
            }

            if ($e instanceof NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource tidak ditemukan.',
                ], 404);
            }

            if ($e instanceof ConflictHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }

            if ($e instanceof UnprocessableEntityHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            // Generic server error
            return response()->json([
                'success' => false,
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Terjadi kesalahan pada server.',
            ], 500);
        });
    })
    ->create();
