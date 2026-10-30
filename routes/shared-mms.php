<?php

use App\Http\Controllers\API\Messaging\SharedMmsController;
use Illuminate\Support\Facades\Route;

Route::prefix('sms/shared-groups')->middleware('throttle:30,1')->group(function () {
    Route::get('capabilities', [SharedMmsController::class, 'capabilities']);
    Route::get('requests/{requestKey}', [SharedMmsController::class, 'requestStatus'])->whereUuid('requestKey');
    Route::get('/', [SharedMmsController::class, 'index']);
    Route::post('/', [SharedMmsController::class, 'store']);
    Route::get('{sharedGroup}', [SharedMmsController::class, 'show'])->whereUuid('sharedGroup');
    Route::post('{sharedGroup}/read', [SharedMmsController::class, 'markRead'])->whereUuid('sharedGroup');
    Route::post('{sharedGroup}/messages', [SharedMmsController::class, 'send'])->whereUuid('sharedGroup');
});
