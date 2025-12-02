<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', function () {
    return response()->json([
        'message' => 'Login first to access this resource.'
    ], 401);
})->name('login');

// iGUIDE webhook endpoint (public, no auth required)
Route::match(['get', 'post'], '/iguide_webhook.php', [App\Http\Controllers\IguideWebhookController::class, 'handle']);