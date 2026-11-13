<?php

use App\Http\Controllers\API\IguideLabController;
use App\Http\Middleware\AuthorizeIguideLab;
use Illuminate\Support\Facades\Route;

// Separate scoped capability; no guest trial, billing, or ordinary Studio access.
Route::middleware([AuthorizeIguideLab::class])->prefix('iguide-lab')->group(function () {
    Route::get('/', [IguideLabController::class, 'index'])->middleware('throttle:iguide-lab-read');
    Route::post('/cases', [IguideLabController::class, 'upload'])->middleware('throttle:iguide-lab-write');
    Route::post('/runs', [IguideLabController::class, 'start'])->middleware('throttle:iguide-lab-write');
    Route::get('/runs/{id}', [IguideLabController::class, 'show'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::post('/runs/{id}/reviews', [IguideLabController::class, 'review'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/cases/{id}/composites', [IguideLabController::class, 'addComposite'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::get('/composites/{id}', [IguideLabController::class, 'showComposite'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::post('/composites/{id}/reviews', [IguideLabController::class, 'reviewComposite'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::get('/assets/{kind}/{id}', [IguideLabController::class, 'asset'])->whereIn('kind', ['case', 'run', 'composite-preview', 'composite-native'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
});
