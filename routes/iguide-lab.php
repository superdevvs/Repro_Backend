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
    Route::post('/composites/{id}/display-metadata', [IguideLabController::class, 'correctDisplayMetadata'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/pipelines', [IguideLabController::class, 'startPipeline'])->middleware('throttle:iguide-lab-write');
    Route::get('/pipelines/{id}', [IguideLabController::class, 'showPipeline'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::post('/analyses', [IguideLabController::class, 'startAnalysis'])->middleware('throttle:iguide-lab-write');
    Route::get('/analyses/{id}', [IguideLabController::class, 'showAnalysis'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::get('/analyses/{id}/views/{view}', [IguideLabController::class, 'analysisView'])->whereUuid('id')->whereIn('view', ['source', 'front', 'right', 'back', 'left', 'zenith', 'nadir'])->middleware('throttle:iguide-lab-read');
    Route::get('/assets/{kind}/{id}', [IguideLabController::class, 'asset'])->whereIn('kind', ['case', 'run', 'composite-preview', 'composite-native', 'pipeline-alpha'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
});
