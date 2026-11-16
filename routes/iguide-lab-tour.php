<?php

use App\Http\Controllers\API\IguideLabTourController;
use App\Http\Middleware\AuthorizeIguideLab;
use Illuminate\Support\Facades\Route;

// Tour analysis layer (Deploy 1). Same scoped capability and throttles as routes/iguide-lab.php, which must not be edited.
Route::middleware([AuthorizeIguideLab::class])->prefix('iguide-lab')->group(function () {
    Route::get('/tour-analyses', [IguideLabTourController::class, 'index'])->middleware('throttle:iguide-lab-read');
    Route::post('/tour-analyses', [IguideLabTourController::class, 'store'])->middleware('throttle:iguide-lab-write');
    Route::get('/tour-analyses/{id}', [IguideLabTourController::class, 'show'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::get('/tour-analyses/{id}/progress', [IguideLabTourController::class, 'progress'])->whereUuid('id')->middleware('throttle:iguide-lab-read');
    Route::post('/tour-analyses/{id}/selection', [IguideLabTourController::class, 'selection'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/tour-analyses/{id}/runs', [IguideLabTourController::class, 'runs'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/tour-analyses/{id}/accept', [IguideLabTourController::class, 'accept'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
    Route::post('/tour-analyses/{id}/cancel', [IguideLabTourController::class, 'cancel'])->whereUuid('id')->middleware('throttle:iguide-lab-write');
});
