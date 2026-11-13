<?php

use App\Http\Controllers\API\Messaging\{MessageMediaController, MessageSendController, MessageContentController};
use Illuminate\Support\Facades\Route;

Route::get('messaging/media/shared/{mediaFile}', [MessageMediaController::class, 'shared'])->whereUuid('mediaFile')->middleware('throttle:120,1')->name('message-media.shared');
Route::middleware(['auth:sanctum', 'throttle:240,1'])->prefix('messaging/media')->group(function () {
    Route::post('uploads', [MessageMediaController::class, 'create']);
    Route::get('uploads/{mediaFile}', [MessageMediaController::class, 'show'])->whereUuid('mediaFile');
    Route::put('uploads/{mediaFile}/chunks/{index}', [MessageMediaController::class, 'chunk'])->whereUuid('mediaFile')->whereNumber('index');
    Route::post('uploads/{mediaFile}/complete', [MessageMediaController::class, 'complete'])->whereUuid('mediaFile');
    Route::delete('uploads/{mediaFile}', [MessageMediaController::class, 'destroy'])->whereUuid('mediaFile');
    Route::get('files/{mediaFile}', [MessageMediaController::class, 'download'])->whereUuid('mediaFile');
    Route::get('legacy/{kind}/{id}/{index}', [MessageMediaController::class, 'legacy'])->whereIn('kind', ['sms', 'email'])->whereNumber('id')->whereNumber('index');
    Route::post('send', [MessageSendController::class, 'send'])->middleware('throttle:30,1');
    Route::post('content', [MessageContentController::class, 'index']);
    Route::put('reactions/{kind}/{id}', [MessageContentController::class, 'react'])->middleware('throttle:30,1');
});
