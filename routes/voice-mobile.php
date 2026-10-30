<?php

use App\Http\Controllers\API\Voice\VoiceMobileController;
use Illuminate\Support\Facades\Route;

// No parent operate gate: owners may end/revoke after permission removal.
Route::prefix('mobile')->middleware('throttle:30,1')->group(function () {
    Route::post('sessions', [VoiceMobileController::class, 'bootstrap']);
    Route::get('sessions/{session}/caller-ids', [VoiceMobileController::class, 'callerIds'])->whereUuid('session');
    Route::post('sessions/{session}/outbound', [VoiceMobileController::class, 'outbound'])->whereUuid('session');
    Route::post('sessions/{session}/incoming/{sdk}/claim', [VoiceMobileController::class, 'claim'])->whereUuid(['session', 'sdk']);
    Route::get('sessions/{session}/calls/{sdk}/state', [VoiceMobileController::class, 'state'])->whereUuid(['session', 'sdk']);
    Route::post('sessions/{session}/calls/{sdk}/end', [VoiceMobileController::class, 'end'])->whereUuid(['session', 'sdk']);
    Route::delete('sessions/{session}', [VoiceMobileController::class, 'revoke'])->whereUuid('session');
});
