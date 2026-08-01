<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\NearbySearchController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/health', [SystemController::class, 'health']);

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/dashboard', [DashboardController::class, 'overview']);
        Route::get('/insights/market', [InsightController::class, 'market']);
        Route::post('/exports/csv', [DashboardController::class, 'exportCsv']);
        Route::post('/exports/excel', [DashboardController::class, 'exportExcel']);

        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::post('/projects/{project}/start', [ProjectController::class, 'start']);
        Route::post('/projects/{project}/cancel', [ProjectController::class, 'cancel']);
        Route::post('/projects/{project}/schedule', [ProjectController::class, 'schedule']);
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);

        Route::get('/businesses', [BusinessController::class, 'index']);
        Route::get('/businesses/{business}', [BusinessController::class, 'show']);
        Route::post('/nearby-search', [NearbySearchController::class, 'search']);

        Route::get('/scans', [SystemController::class, 'scans']);
        Route::get('/notifications', [SystemController::class, 'notifications']);
        Route::post('/notifications/{notification}/read', [SystemController::class, 'markNotificationRead']);
        Route::post('/notifications/read-all', [SystemController::class, 'markAllNotificationsRead']);
        Route::get('/logs', [SystemController::class, 'logs'])->middleware('role:admin,operator');
    });
});
