<?php

use App\Http\Controllers\API\MobileAssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:60,1'])->prefix('mobile/assistant')->group(function () {
    Route::get('capabilities', [MobileAssistantController::class, 'capabilities']);
    Route::post('query', [MobileAssistantController::class, 'query']);
    Route::post('actions/prepare', [MobileAssistantController::class, 'prepare']);
    Route::post('actions/{id}/confirm', [MobileAssistantController::class, 'confirm'])->whereUuid('id');
    Route::post('actions/{id}/claim-handoff', [MobileAssistantController::class, 'claimHandoff'])->whereUuid('id');
    Route::get('actions/{id}', [MobileAssistantController::class, 'receipt'])->whereUuid('id');
    Route::delete('actions/{id}', [MobileAssistantController::class, 'cancel'])->whereUuid('id');
});
