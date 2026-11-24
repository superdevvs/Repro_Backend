<?php

use App\Http\Controllers\API\Crm\PublicCareersController;
use Illuminate\Support\Facades\Route;

// Public careers pages. Off with the rest of the CRM.
Route::middleware('crm.enabled')->prefix('careers')->name('careers.')->group(function () {
    Route::get('/', [PublicCareersController::class, 'index'])->name('index');
    Route::post('apply', [PublicCareersController::class, 'apply'])->middleware('throttle:6,1,careers-apply:')->name('apply.general');
    Route::get('{slug}', [PublicCareersController::class, 'show'])->where('slug', '[a-z0-9\-]+')->name('show');
    Route::post('{slug}/apply', [PublicCareersController::class, 'apply'])->where('slug', '[a-z0-9\-]+')->middleware('throttle:6,1,careers-apply:')->name('apply');
});
