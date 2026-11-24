<?php

use App\Http\Controllers\API\Crm\PublicCrmQuoteController;
use Illuminate\Support\Facades\Route;

// The client's quote page. Off with the rest of the CRM.
Route::middleware('crm.enabled')->prefix('quotes')->name('quotes.')->group(function () {
    Route::get('{token}', [PublicCrmQuoteController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->name('show');
    Route::post('{token}/accept', [PublicCrmQuoteController::class, 'accept'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,crm-quote-respond:')->name('accept');
    Route::post('{token}/decline', [PublicCrmQuoteController::class, 'decline'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,crm-quote-respond:')->name('decline');
});
