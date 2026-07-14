<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\MonitoringEventController;
use App\Http\Controllers\Api\V1\ServiceOfferingController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WorkItemController;
use App\Http\Middleware\EnsureGlobalAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth')->group(function (): void {
    Route::apiResource('tenants', TenantController::class)->only([
        'index',
        'store',
        'show',
    ])->middleware(EnsureGlobalAdmin::class);

    Route::get('work-items', [WorkItemController::class, 'index']);
    Route::post('work-items', [WorkItemController::class, 'store']);
    Route::get('work-items/{work_item}', [WorkItemController::class, 'show']);
    Route::patch('work-items/{work_item}/status', [WorkItemController::class, 'updateStatus']);
    Route::post('work-items/{work_item}/messages', [WorkItemController::class, 'storeMessage']);

    Route::get('assets', [AssetController::class, 'index']);
    Route::post('assets', [AssetController::class, 'store']);
    Route::get('assets/{asset}', [AssetController::class, 'show']);

    Route::get('suppliers', [SupplierController::class, 'index']);
    Route::post('suppliers', [SupplierController::class, 'store']);
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);

    Route::get('service-offerings', [ServiceOfferingController::class, 'index']);
    Route::post('service-offerings', [ServiceOfferingController::class, 'store']);
    Route::get('service-offerings/{service_offering}', [ServiceOfferingController::class, 'show']);

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
