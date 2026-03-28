<?php

use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Middleware\EnsureGlobalAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth')->group(function (): void {
    Route::apiResource('tenants', TenantController::class)->only([
        'index',
        'store',
        'show',
    ])->middleware(EnsureGlobalAdmin::class);

    Route::get('incidents', [IncidentController::class, 'index']);
    Route::post('incidents', [IncidentController::class, 'store']);
    Route::get('incidents/{incident}', [IncidentController::class, 'show']);
    Route::patch('incidents/{incident}/status', [IncidentController::class, 'updateStatus']);
    Route::post('incidents/{incident}/messages', [IncidentController::class, 'storeMessage']);
});
