<?php

use App\Http\Controllers\API\AryeoAdminController as Admin;
use App\Http\Controllers\API\AryeoWorkerController as Worker;
use App\Http\Middleware\AuthenticateAryeoWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations/aryeo/v1')->middleware([AuthenticateAryeoWorker::class, 'throttle:120,1'])->group(function () {
    Route::post('heartbeat', [Worker::class, 'heartbeat']);
    Route::get('shoots', [Worker::class, 'shoots']);
    Route::get('shoots/{shoot}', [Worker::class, 'show'])->whereNumber('shoot');
    Route::get('shoots/{shoot}/readiness', [Worker::class, 'readiness'])->whereNumber('shoot');
    Route::get('changes', [Worker::class, 'changes']);
    Route::post('requests', [Worker::class, 'discover']);
    Route::put('requests/{order}/inventory', [Worker::class, 'inventory'])->whereNumber('order');
    Route::get('requests/{order}/assets/{asset}/original', [Worker::class, 'approvedOriginal'])->whereNumber('order')->whereNumber('asset');
    Route::post('jobs/claim', [Worker::class, 'claim']);
    Route::get('jobs/{job}', [Worker::class, 'job'])->whereUuid('job');
    Route::post('jobs/{job}/renew', [Worker::class, 'renew'])->whereUuid('job');
    Route::post('jobs/{job}/authorize', [Worker::class, 'authorize'])->whereUuid('job');
    Route::post('jobs/{job}/result', [Worker::class, 'result'])->whereUuid('job');
    Route::post('jobs/{job}/assets/{asset}/download', [Worker::class, 'download'])->whereUuid('job')->whereNumber('asset');
});
Route::prefix('shoots/{shoot}/aryeo')->whereNumber('shoot')->middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager', 'throttle:120,1'])->group(function () {
    Route::get('/', [Admin::class, 'panel']);
    Route::post('requests/{order}/match', [Admin::class, 'match'])->whereNumber('order');
    Route::post('requests/{order}/process', [Admin::class, 'process'])->whereNumber('order');
    Route::post('jobs/{job}/retry', [Admin::class, 'retry'])->whereUuid('job');
});
