<?php

use App\Http\Controllers\API\Voice\VoiceBrowserController;
use Illuminate\Support\Facades\Route;

// Session endpoints authorize operate OR supervise internally; the parent requires view.
Route::get('browser/config', [VoiceBrowserController::class, 'config']);
Route::post('browser/sessions', [VoiceBrowserController::class, 'connect'])->middleware('throttle:20,1');
Route::get('browser/sessions/{session}', [VoiceBrowserController::class, 'session']);
Route::post('browser/sessions/{session}/token', [VoiceBrowserController::class, 'token'])->middleware('throttle:20,1');
Route::post('browser/sessions/{session}/heartbeat', [VoiceBrowserController::class, 'heartbeat']);
Route::delete('browser/sessions/{session}', [VoiceBrowserController::class, 'disconnect']);
Route::get('calls/{call}/browser', [VoiceBrowserController::class, 'state']);
Route::post('calls/human', [VoiceBrowserController::class, 'outbound'])->middleware('permission:voice-calls,operate');
Route::post('calls/{call}/takeover', [VoiceBrowserController::class, 'takeover'])->middleware('permission:voice-calls,operate');
Route::post('calls/{call}/supervise', [VoiceBrowserController::class, 'supervise'])->middleware('permission:voice-calls,supervise');
Route::patch('calls/{call}/supervise', [VoiceBrowserController::class, 'changeSupervision'])->middleware('permission:voice-calls,supervise');
Route::delete('calls/{call}/supervise', [VoiceBrowserController::class, 'leaveSupervision'])->middleware('permission:voice-calls,supervise');
Route::post('calls/{call}/browser-actions', [VoiceBrowserController::class, 'action'])->middleware('permission:voice-calls,operate');
Route::patch('calls/{call}/browser-consent', [VoiceBrowserController::class, 'consent'])->middleware('permission:voice-calls,operate');
