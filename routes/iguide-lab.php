<?php

use App\Http\Controllers\API\IguideLabController;
use App\Http\Controllers\API\IguideLabGeometryController;
use App\Http\Controllers\API\IguideLabWorkflowController;
use App\Http\Controllers\API\IguideLabImportController;
use App\Http\Middleware\AuthorizeIguideLab;
use Illuminate\Support\Facades\Route;

// Separate scoped capability; no guest trial, billing, or ordinary Studio access.
Route::middleware([AuthorizeIguideLab::class])->prefix('iguide-lab')->group(function () {
    Route::get('/workflows', [IguideLabWorkflowController::class, 'index'])->middleware('throttle:iguide-lab-read');
    Route::post('/workflows', [IguideLabWorkflowController::class, 'start'])->middleware('throttle:iguide-lab-write');
    Route::get('/workflows/{id}', [IguideLabWorkflowController::class, 'show'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::post('/workflows/{id}/cancel', [IguideLabWorkflowController::class, 'cancel'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::get('/', [IguideLabController::class, 'index'])->middleware('throttle:iguide-lab-read');
    Route::post('/cases', [IguideLabController::class, 'upload'])->middleware('throttle:iguide-lab-write');
    Route::post('/imports', [IguideLabImportController::class, 'store'])->middleware('throttle:iguide-lab-write');
    Route::get('/imports', [IguideLabImportController::class, 'index'])->middleware('throttle:iguide-lab-read');
    Route::get('/imports/{id}', [IguideLabImportController::class, 'show'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::get('/imports/{id}/sources/{caseId}/preview', [IguideLabImportController::class, 'preview'])->whereUuid(['id', 'caseId'])->middleware('throttle:iguide-lab-read');
    Route::post('/import-uploads', [IguideLabImportController::class, 'startUpload'])->middleware('throttle:iguide-lab-write');
    Route::get('/import-uploads/{id}', [IguideLabImportController::class, 'showUpload'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::put('/import-uploads/{id}/chunks/{index}', [IguideLabImportController::class, 'uploadChunk'])->whereUuid('id')->whereNumber('index')->middleware('throttle:iguide-lab-write');
    Route::post('/import-uploads/{id}/finalize', [IguideLabImportController::class, 'finalizeUpload'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/cases/{id}/native-source', [IguideLabGeometryController::class, 'attachNative'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/sections', [IguideLabGeometryController::class, 'startSection'])->middleware('throttle:iguide-lab-write');
    Route::get('/sections/{id}', [IguideLabGeometryController::class, 'showSection'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::post('/sections/{id}/masks', [IguideLabGeometryController::class, 'addSectionMask'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/pipelines/{id}/masks', [IguideLabGeometryController::class, 'addPipelineMask'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::get('/masks/{id}/asset', [IguideLabGeometryController::class, 'maskAsset'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::post('/sections/{id}/compositions', [IguideLabGeometryController::class, 'composeSection'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/pipelines/{id}/compositions', [IguideLabGeometryController::class, 'composePipeline'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::get('/compositions/{id}', [IguideLabGeometryController::class, 'showComposition'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::get('/compositions/{id}/alpha', [IguideLabGeometryController::class, 'compositionAlpha'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::get('/compositions/{id}/harmonization-clipping', [IguideLabGeometryController::class, 'compositionClipping'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
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
    Route::get('/analyses/{id}/views/{view}', [IguideLabController::class, 'analysisView'])->whereUuid('id')->whereIn('view', ['source', 'front', 'right', 'back', 'left', 'zenith', 'nadir', 'parent_source', 'parent_front', 'parent_right', 'parent_back', 'parent_left'])->middleware('throttle:iguide-lab-read');
    Route::get('/assets/{kind}/{id}', [IguideLabController::class, 'asset'])->whereIn('kind', ['case', 'run', 'composite-preview', 'composite-native', 'pipeline-alpha'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
});
