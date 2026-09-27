<?php

use App\Http\Controllers\API\ShootUnitTourController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('shoots/{shoot}/units/{unit}')->group(function () {
    Route::patch('tour', [ShootUnitTourController::class, 'update']);
    Route::post('{provider}/{operation}', [ShootUnitTourController::class, 'provider'])
        ->whereIn('provider', ['iguide', 'cubicasa'])->whereIn('operation', ['identifiers', 'sync', 'order']);
});

foreach (['integrations/shoots/{shoot}/units/{unit}/iguide/offline-package', 'integrations/shoots/{shoot}/units/{unit}/lines/{tourLine}/iguide/offline-package'] as $prefix) {
Route::post($prefix.'/view-link', \App\Http\Controllers\API\IguideOfflineViewerLinkController::class)
    ->middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,client', \App\Http\Middleware\ScopeShootUnitTour::class]);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager', \App\Http\Middleware\ScopeShootUnitTour::class])
    ->prefix($prefix)->group(function () {
        Route::post('/', [\App\Http\Controllers\API\IguideOfflinePackageController::class, 'store']);
        Route::post('/uploads', [\App\Http\Controllers\API\IguideOfflineChunkUploadController::class, 'store']);
        Route::get('/uploads/{upload}', [\App\Http\Controllers\API\IguideOfflineChunkUploadController::class, 'show'])->whereUuid('upload');
        Route::put('/uploads/{upload}/chunks/{index}', [\App\Http\Controllers\API\IguideOfflineChunkUploadController::class, 'storeChunk'])->whereUuid('upload')->whereNumber('index');
        Route::post('/uploads/{upload}/complete', [\App\Http\Controllers\API\IguideOfflineChunkUploadController::class, 'complete'])->whereUuid('upload');
        Route::delete('/uploads/{upload}', [\App\Http\Controllers\API\IguideOfflineChunkUploadController::class, 'destroy'])->whereUuid('upload');
    });

}
