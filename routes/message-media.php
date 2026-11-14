<?php

use App\Http\Controllers\API\Messaging\{MessageMediaController, MessageSendController, MessageContentController};
use Illuminate\Support\Facades\Route;

Route::get('messaging/media/shared/{mediaFile}', [MessageMediaController::class, 'shared'])->whereUuid('mediaFile')->middleware('throttle:120,1,message-media-public:')->name('message-media.shared');
Route::get('messaging/media/bundle/{mediaFile}', [MessageMediaController::class, 'bundle'])->whereUuid('mediaFile')->middleware('throttle:120,1,message-media-public:')->name('message-media.bundle');
// Separate the upload/read bucket from send and reaction limits. A large file
// needs many chunk requests and must not exhaust the next message's send limit.
Route::middleware(['auth:sanctum', 'throttle:240,1,message-media:'])->prefix('messaging/media')->group(function () {
    Route::post('uploads', [MessageMediaController::class, 'create']);
    Route::get('uploads/{mediaFile}', [MessageMediaController::class, 'show'])->whereUuid('mediaFile');
    Route::put('uploads/{mediaFile}/chunks/{index}', [MessageMediaController::class, 'chunk'])->whereUuid('mediaFile')->whereNumber('index');
    Route::post('uploads/{mediaFile}/complete', [MessageMediaController::class, 'complete'])->whereUuid('mediaFile');
    Route::delete('uploads/{mediaFile}', [MessageMediaController::class, 'destroy'])->whereUuid('mediaFile');
    Route::get('files/{mediaFile}', [MessageMediaController::class, 'download'])->whereUuid('mediaFile');
    Route::get('legacy/{kind}/{id}/{index}', [MessageMediaController::class, 'legacy'])->whereIn('kind', ['sms', 'email'])->whereNumber('id')->whereNumber('index');
    Route::post('send', [MessageSendController::class, 'send'])->middleware('throttle:30,1,message-media-send:');
    Route::post('content', [MessageContentController::class, 'index']);
    Route::put('reactions/{kind}/{id}', [MessageContentController::class, 'react'])->middleware('throttle:30,1,message-media-reactions:');
});
