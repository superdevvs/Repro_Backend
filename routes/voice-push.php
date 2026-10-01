<?php

use App\Http\Controllers\API\Voice\VoicePushController;
use Illuminate\Support\Facades\Route;

Route::get('push/settings', [VoicePushController::class, 'settings']);
Route::patch('push/settings', [VoicePushController::class, 'preferences']);
Route::post('push/subscriptions', [VoicePushController::class, 'subscribe'])->middleware('throttle:20,1');
Route::delete('push/subscriptions/{subscription}', [VoicePushController::class, 'delete']);
Route::post('push/subscriptions/{subscription}/test', [VoicePushController::class, 'test'])->middleware('throttle:5,1');
Route::get('push/deliveries/{delivery}', [VoicePushController::class, 'delivery']);
