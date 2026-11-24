<?php

use App\Http\Controllers\API\Crm\CrmEventController;
use Illuminate\Support\Facades\Route;

Route::get('follow-ups', [CrmEventController::class, 'followUps'])->name('follow-ups');
Route::get('event-types', [CrmEventController::class, 'types'])->name('event-types.index');
Route::post('event-types', [CrmEventController::class, 'storeType'])->name('event-types.store');
Route::patch('event-types/{type}', [CrmEventController::class, 'updateType'])->whereNumber('type')->name('event-types.update');
Route::get('contacts/{contact}/events', [CrmEventController::class, 'forContact'])->whereNumber('contact')->name('events.for-contact');
Route::post('contacts/{contact}/events', [CrmEventController::class, 'store'])->whereNumber('contact')->name('events.store');
Route::patch('events/{event}', [CrmEventController::class, 'update'])->whereNumber('event')->name('events.update');
Route::post('events/{event}/complete', [CrmEventController::class, 'complete'])->whereNumber('event')->name('events.complete');
