<?php

use App\Http\Controllers\API\ListingStudioBillingRequestController;
use App\Http\Controllers\API\ListingStudioDelegatedBillingController;
use App\Http\Middleware\EnsureListingStudioClient;
use App\Http\Middleware\EnsureListingStudioDelegate;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', EnsureListingStudioDelegate::class, 'throttle:60,1'])->prefix('listing-studio/staff')->group(function (): void {
    $controller = ListingStudioDelegatedBillingController::class;
    Route::get('clients', [$controller, 'clients']);
    Route::prefix('clients/{clientId}')->whereNumber('clientId')->group(function () use ($controller): void {
        Route::get('catalog', [$controller, 'catalog']);
        Route::get('account', [$controller, 'account']);
        Route::post('uploads', [$controller, 'upload'])->middleware('throttle:15,1');
        Route::get('uploads/{uploadId}/preview', [$controller, 'uploadPreview'])->whereUuid('uploadId');
        Route::post('quotes', [$controller, 'quote'])->middleware('throttle:30,1');
        Route::get('orders', [$controller, 'orders']);
        Route::post('orders', [$controller, 'storeOrder'])->middleware('throttle:20,1');
        Route::get('orders/{orderId}', [$controller, 'order'])->whereUuid('orderId');
        Route::get('orders/{orderId}/preview', [$controller, 'orderPreview'])->whereUuid('orderId');
        Route::get('billing-requests', [$controller, 'requests']);
        Route::post('billing-requests', [$controller, 'createRequest'])->middleware('throttle:20,1');
        Route::get('billing-requests/{requestId}', [$controller, 'showRequest'])->whereUuid('requestId');
        Route::post('billing-requests/{requestId}/email', [$controller, 'email'])->whereUuid('requestId')->middleware('throttle:10,1');
        Route::post('billing-requests/{requestId}/cancel', [$controller, 'cancelRequest'])->whereUuid('requestId');
        Route::post('subscription-actions', [$controller, 'subscriptionAction'])->middleware('throttle:10,1');
    });
});

Route::middleware(['auth:sanctum', EnsureListingStudioClient::class, 'throttle:30,1'])->prefix('listing-studio/client/billing-requests')->group(function (): void {
    Route::get('{requestId}', [ListingStudioBillingRequestController::class, 'show'])->whereUuid('requestId');
    Route::post('{requestId}/confirm', [ListingStudioBillingRequestController::class, 'confirm'])->whereUuid('requestId')->middleware('throttle:10,1');
    Route::post('{requestId}/cancel', [ListingStudioBillingRequestController::class, 'cancel'])->whereUuid('requestId');
});
