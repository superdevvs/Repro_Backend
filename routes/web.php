<?php

use App\Http\Controllers\API\LinkPreviewController;
use App\Services\LinkPreview\LinkPreviewService;
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

// CubiCasa webhook endpoint (public, no auth required)
Route::match(['get', 'post'], '/cubicasa_webhook.php', [App\Http\Controllers\CubiCasaWebhookController::class, 'handle']);

Route::get('/link-preview/{type}', [LinkPreviewController::class, 'document'])
    ->whereIn('type', array_merge(LinkPreviewService::TOUR_TYPES, LinkPreviewService::STATIC_TYPES))
    ->middleware('throttle:60,1')
    ->name('link-preview.document');