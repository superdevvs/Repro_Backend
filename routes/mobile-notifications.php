<?php

use App\Http\Controllers\API\MobileNotificationController;
use App\Http\Controllers\API\MobileDevicePreferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:30,1'])->prefix('mobile-notifications')->group(function () {
    Route::get('capabilities', [MobileNotificationController::class, 'capabilities']);
    Route::get('preferences', [MobileDevicePreferenceController::class, 'show']);
    Route::put('preferences', [MobileDevicePreferenceController::class, 'update']);
    Route::put('devices/{installation}', [MobileNotificationController::class, 'register'])->whereUuid('installation');
    Route::delete('devices/{installation}', [MobileNotificationController::class, 'revoke'])->whereUuid('installation');
});
