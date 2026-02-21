<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Internal Procurement System
| Base URL: /api/v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── Auth (public) ────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('login',  [AuthController::class, 'login']);
    });

    // ── Authenticated routes ─────────────────────────────────
    Route::middleware('auth.token')->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me',      [AuthController::class, 'me']);
        });

        // ── Requests ─────────────────────────────────────────
        Route::prefix('requests')->group(function () {

            // GET  /requests?status=approved   — all roles (filtered)
            Route::get('/', [RequestController::class, 'index']);

            // POST /requests                   — EMPLOYEE only
            Route::post('/', [RequestController::class, 'store'])
                ->middleware('role:EMPLOYEE');

            // GET  /requests/{id}              — all roles (Employee: own only)
            Route::get('/{id}', [RequestController::class, 'show']);

            // GET  /requests/{id}/history      — all roles
            Route::get('/{id}/history', [RequestController::class, 'history']);

            // POST /requests/{id}/submit       — EMPLOYEE
            Route::post('/{id}/submit', [RequestController::class, 'submit'])
                ->middleware('role:EMPLOYEE');

            // POST /requests/{id}/verify       — PURCHASING
            Route::post('/{id}/verify', [RequestController::class, 'verify'])
                ->middleware('role:PURCHASING');

            // POST /requests/{id}/approve      — PURCHASING_MANAGER
            Route::post('/{id}/approve', [RequestController::class, 'approve'])
                ->middleware('role:PURCHASING_MANAGER');

            // POST /requests/{id}/reject       — PURCHASING or PURCHASING_MANAGER
            Route::post('/{id}/reject', [RequestController::class, 'reject'])
                ->middleware('role:PURCHASING,PURCHASING_MANAGER');

            // POST /requests/{id}/procure      — PURCHASING
            Route::post('/{id}/procure', [RequestController::class, 'procure'])
                ->middleware('role:PURCHASING');
        });

        // ── Reports ───────────────────────────────────────────
        // Only PURCHASING, PURCHASING_MANAGER, and ADMIN can access reports
        Route::prefix('reports')
            ->middleware('role:PURCHASING,PURCHASING_MANAGER,ADMIN')
            ->group(function () {
                Route::get('top-departments',  [ReportController::class, 'topDepartments']);
                Route::get('category-per-month', [ReportController::class, 'categoryPerMonth']);
                Route::get('lead-time',        [ReportController::class, 'leadTime']);
            });
    });
});
