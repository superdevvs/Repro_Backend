<?php

use App\Http\Controllers\Copilot\McpController;
use App\Http\Controllers\Copilot\OAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('copilot')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('settings', [\App\Http\Controllers\Copilot\SettingsController::class, 'show']);
        Route::put('settings', [\App\Http\Controllers\Copilot\SettingsController::class, 'update']);
    });
    Route::match(['get', 'post', 'delete'], 'mcp', [McpController::class, 'handle'])->middleware('throttle:120,1');
    Route::prefix('oauth')->group(function () {
        Route::get('metadata', [OAuthController::class, 'metadata']);
        Route::get('resource', [OAuthController::class, 'resource']);
        Route::post('register', [OAuthController::class, 'register'])->middleware('throttle:20,1');
        Route::get('authorize', [OAuthController::class, 'authorize'])->middleware('throttle:30,1');
        Route::post('token', [OAuthController::class, 'token'])->middleware('throttle:60,1');
        Route::post('revoke', [OAuthController::class, 'revoke'])->middleware('throttle:60,1');
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('requests/{id}', [OAuthController::class, 'consentInfo'])->whereUuid('id');
            Route::post('requests/{id}', [OAuthController::class, 'consent'])->whereUuid('id')->middleware('throttle:20,1');
            Route::get('connections', [OAuthController::class, 'connections']);
            Route::delete('connections/{id}', [OAuthController::class, 'disconnect'])->whereUuid('id');
        });
    });
});
