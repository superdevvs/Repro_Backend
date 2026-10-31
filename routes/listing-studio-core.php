<?php

use App\Http\Controllers\API\ListingStudioClientController;
use App\Http\Middleware\EnsureListingStudioClient;
use App\Http\Middleware\ValidateListingStudioBridge;
use Illuminate\Support\Facades\Route;

// The existing website bridge can read this catalog but cannot create paid orders.
Route::get('external/listing-studio/catalog', [ListingStudioClientController::class, 'catalog'])
    ->middleware([ValidateListingStudioBridge::class, 'throttle:120,1'])->name('listing-studio.catalog.external');

Route::middleware(['auth:sanctum', EnsureListingStudioClient::class, 'throttle:60,1'])
    ->prefix('listing-studio/client')->group(function () {
        Route::get('catalog', [ListingStudioClientController::class, 'catalog']);
        Route::get('account', [ListingStudioClientController::class, 'account']);
        Route::get('transactions', [ListingStudioClientController::class, 'transactions']);
        Route::get('orders', [ListingStudioClientController::class, 'index']);
        Route::get('orders/{orderId}', [ListingStudioClientController::class, 'show'])->whereUuid('orderId');
        Route::post('quotes', [ListingStudioClientController::class, 'quote'])->middleware('throttle:30,1');
        Route::post('orders', [ListingStudioClientController::class, 'store'])->middleware('throttle:20,1');
        Route::post('orders/{orderId}/cancel', [ListingStudioClientController::class, 'cancel'])->whereUuid('orderId');
        require __DIR__.'/listing-studio-payments.php';
    });
