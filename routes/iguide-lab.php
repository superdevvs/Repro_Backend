<?php

use App\Http\Controllers\API\IguideLabController;
use App\Http\Middleware\AuthorizeIguideLab;
use Illuminate\Support\Facades\Route;

// Separate scoped capability; no guest trial, billing, or ordinary Studio access.
Route::middleware(['throttle:180,1', AuthorizeIguideLab::class])->prefix('iguide-lab')->group(function () {
    Route::get('/', [IguideLabController::class, 'index']);
    Route::post('/cases', [IguideLabController::class, 'upload'])->middleware('throttle:30,1');
    Route::post('/runs', [IguideLabController::class, 'start'])->middleware('throttle:30,1');
    Route::get('/runs/{id}', [IguideLabController::class, 'show'])->whereUuid('id');
    Route::post('/runs/{id}/reviews', [IguideLabController::class, 'review'])->whereUuid('id')->middleware('throttle:30,1');
    Route::get('/assets/{kind}/{id}', [IguideLabController::class, 'asset'])->whereIn('kind', ['case', 'run'])->whereUuid('id');
});
