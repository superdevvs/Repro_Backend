<?php

use App\Http\Controllers\API\Messaging\GroundwireSmsController;
use Illuminate\Support\Facades\Route;

Route::get('groundwire/sms/provision', [GroundwireSmsController::class, 'provision'])->middleware('throttle:60,1');
// SIP password authentication occurs in the controller, POST body only.
Route::post('groundwire/sms/{action}', [GroundwireSmsController::class, 'execute'])
    ->whereIn('action', ['send', 'fetch', 'push-token', 'remove'])->middleware('throttle:120,1');
