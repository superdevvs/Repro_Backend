<?php

use App\Http\Controllers\API\ListingStudioPaymentController;
use Illuminate\Support\Facades\Route;

// Required by the authenticated client group in api.php; existing verification remains in force.
Route::middleware(\App\Http\Middleware\EnsureListingStudioClient::class)->group(function () {
    Route::post('orders/{orderId}/checkout', [ListingStudioPaymentController::class, 'order'])->whereUuid('orderId')->middleware('throttle:20,1');
    Route::post('subscription-checkout', [ListingStudioPaymentController::class, 'subscription'])->middleware('throttle:20,1');
    Route::post('billing-portal', [ListingStudioPaymentController::class, 'portal'])->middleware('throttle:20,1');
});
