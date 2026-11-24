<?php

use App\Http\Controllers\API\Crm\CrmCompanyController;
use App\Http\Controllers\API\Crm\CrmConversationController;
use App\Http\Controllers\API\Crm\CrmHomeController;
use App\Http\Controllers\API\Crm\CrmQuoteController;
use App\Http\Controllers\API\Crm\CrmRobbieController;
use Illuminate\Support\Facades\Route;

// Home, goals, reports
Route::get('home', [CrmHomeController::class, 'home'])->name('home');
Route::get('goals', [CrmHomeController::class, 'goals'])->name('goals.index');
Route::put('goals', [CrmHomeController::class, 'setGoals'])->name('goals.set');
Route::get('reports', [CrmHomeController::class, 'reports'])->name('reports');

// Companies
Route::get('companies', [CrmCompanyController::class, 'index'])->name('companies.index');
Route::get('companies/{company}', [CrmCompanyController::class, 'show'])->whereNumber('company')->name('companies.show');
Route::patch('companies/{company}', [CrmCompanyController::class, 'update'])->whereNumber('company')->name('companies.update');

// Quotes
Route::get('quotes/catalog', [CrmQuoteController::class, 'catalog'])->name('quotes.catalog');
Route::get('quotes', [CrmQuoteController::class, 'index'])->name('quotes.index');
Route::get('quotes/{quote}', [CrmQuoteController::class, 'show'])->whereNumber('quote')->name('quotes.show');
Route::patch('quotes/{quote}', [CrmQuoteController::class, 'update'])->whereNumber('quote')->name('quotes.update');
Route::post('quotes/{quote}/{action}', [CrmQuoteController::class, 'action'])->whereNumber('quote')->whereIn('action', ['submit', 'approve', 'send', 'accept', 'decline'])->name('quotes.action');
Route::get('contacts/{contact}/quotes', [CrmQuoteController::class, 'forContact'])->whereNumber('contact')->name('quotes.for-contact');
Route::post('contacts/{contact}/quotes', [CrmQuoteController::class, 'store'])->whereNumber('contact')->name('quotes.store');

// Conversations
Route::get('contacts/{contact}/conversation', [CrmConversationController::class, 'show'])->whereNumber('contact')->name('conversation.show');
Route::post('contacts/{contact}/sms-consent', [CrmConversationController::class, 'consent'])->whereNumber('contact')->name('conversation.consent');

// Robbie
Route::prefix('robbie')->name('robbie.')->group(function () {
    Route::get('insights', [CrmRobbieController::class, 'insights'])->name('insights');
    Route::get('plan-day', [CrmRobbieController::class, 'planDay'])->name('plan-day');
    Route::get('contacts/{contact}/brief', [CrmRobbieController::class, 'brief'])->whereNumber('contact')->middleware('throttle:30,1,crm-robbie-brief:')->name('brief');
    Route::post('chat', [CrmRobbieController::class, 'chat'])->middleware('throttle:20,1,crm-robbie:')->name('chat');
    Route::get('proposals', [CrmRobbieController::class, 'pending'])->name('proposals.index');
    Route::post('proposals/{proposal}/confirm', [CrmRobbieController::class, 'confirm'])->whereNumber('proposal')->name('proposals.confirm');
    Route::post('proposals/{proposal}/dismiss', [CrmRobbieController::class, 'dismiss'])->whereNumber('proposal')->name('proposals.dismiss');
    Route::post('contacts/{contact}/draft-email', [CrmRobbieController::class, 'draftEmail'])->whereNumber('contact')->middleware('throttle:20,1,crm-robbie:')->name('draft-email');
    Route::post('events/{event}/draft-outcome', [CrmRobbieController::class, 'draftOutcome'])->whereNumber('event')->middleware('throttle:20,1,crm-robbie:')->name('draft-outcome');
});
