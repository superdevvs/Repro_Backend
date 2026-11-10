<?php

use App\Http\Controllers\API\AryeoApiController as Api;
use App\Http\Controllers\API\AryeoDownloadController;
use App\Http\Controllers\API\AryeoOAuthController;
use App\Http\Controllers\API\AryeoWebhookController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'HEAD'], 'integrations/aryeo/download/{token}', AryeoDownloadController::class)->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:600,1');
Route::post('integrations/aryeo/webhooks/{installation}', AryeoWebhookController::class)->whereUuid('installation')->middleware('throttle:600,1');
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager', 'throttle:120,1'])->group(function () {
    Route::get('shoots/{shoot}/aryeo-api', [Api::class, 'panel'])->whereNumber('shoot');
    Route::post('shoots/{shoot}/aryeo-api/destinations', [Api::class, 'bind'])->whereNumber('shoot');
    Route::prefix('integrations/aryeo/api')->group(function () {
        Route::get('installations', [Api::class, 'installations']);
        Route::get('alerts', [Api::class, 'alerts']);
        Route::post('alerts/acknowledge', [Api::class, 'acknowledgeAlert']);
        Route::post('cases/{case}/resolve', [Api::class, 'resolveCase'])->whereUuid('case');
        Route::post('installations', [Api::class, 'createInstallation'])->middleware('role:admin,superadmin');
        Route::patch('installations/{installation}', [Api::class, 'updateInstallation'])->whereUuid('installation')->middleware('role:admin,superadmin');
        Route::post('installations/{installation}/connect', [AryeoOAuthController::class, 'start'])->whereUuid('installation')->middleware('role:admin,superadmin');
        Route::patch('destinations/{destination}/hold', [Api::class, 'hold'])->whereUuid('destination');
        Route::post('destinations/{destination}/preview', [Api::class, 'preview'])->whereUuid('destination');
        Route::post('destinations/{destination}/handoff', [Api::class, 'handoff'])->whereUuid('destination');
        Route::post('exports/{export}/actions/{action}', [Api::class, 'action'])->whereUuid('export');
        Route::post('exports/{export}/evidence', [Api::class, 'evidence'])->whereUuid('export');
        Route::post('exports/{export}/cases', [Api::class, 'correction'])->whereUuid('export');
        Route::patch('exports/{export}/metrics', [Api::class, 'metrics'])->whereUuid('export');
        Route::get('exports/{export}/support', [Api::class, 'support'])->whereUuid('export');
    });
});
