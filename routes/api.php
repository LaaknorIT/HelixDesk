<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\MonitoringEventController;
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

    Route::get('assets', [AssetController::class, 'index']);
    Route::post('assets', [AssetController::class, 'store']);
    Route::get('assets/{asset}', [AssetController::class, 'show']);

    Route::get('monitoring-events', [MonitoringEventController::class, 'index']);
    Route::post('monitoring-events', [MonitoringEventController::class, 'store']);
    Route::get('monitoring-events/{monitoring_event}', [MonitoringEventController::class, 'show']);

    Route::get('events', [MonitoringEventController::class, 'index']);
    Route::post('events', [MonitoringEventController::class, 'store']);
    Route::get('events/{monitoring_event}', [MonitoringEventController::class, 'show']);

    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store']);
    Route::get('documents/{document}', [DocumentController::class, 'show']);
});
