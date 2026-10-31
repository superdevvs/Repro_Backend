<?php

use App\Http\Controllers\API\ListingStudioPaidMediaController;
use App\Http\Controllers\API\ListingStudioRevisionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureListingStudioClient::class, 'throttle:60,1'])->prefix('listing-studio/client')->group(function () {
    Route::post('/uploads', [ListingStudioPaidMediaController::class, 'upload'])->middleware('throttle:15,1');
    Route::get('/uploads/{upload}/preview', [ListingStudioPaidMediaController::class, 'uploadPreview'])->whereUuid('upload');
    Route::get('/orders/{order}/preview', [ListingStudioPaidMediaController::class, 'preview'])->whereUuid('order');
    Route::get('/orders/{order}/download', [ListingStudioPaidMediaController::class, 'download'])->whereUuid('order');
});

Route::middleware(['auth:sanctum', 'throttle:90,1'])->prefix('listing-studio/team/revisions')->group(function () {
    Route::get('/', [ListingStudioRevisionController::class, 'index']);
    Route::get('/{task}', [ListingStudioRevisionController::class, 'show'])->whereUuid('task');
    Route::get('/{task}/assignees', [ListingStudioRevisionController::class, 'assignees'])->whereUuid('task');
    Route::get('/{task}/{mode}', [ListingStudioRevisionController::class, 'media'])->whereUuid('task')->whereIn('mode', ['preview', 'source']);
    Route::match(['post', 'patch'], '/{task}/{action}', [ListingStudioRevisionController::class, 'action'])->whereUuid('task')->whereIn('action', ['assignment', 'start', 'hold', 'fail', 'cancel']);
    Route::post('/{task}/deliver', [ListingStudioRevisionController::class, 'deliver'])->whereUuid('task');
    Route::post('/{task}/delivery-attempts/{attempt}/resume', [ListingStudioRevisionController::class, 'resume'])->whereUuid('task')->whereUuid('attempt');
});
