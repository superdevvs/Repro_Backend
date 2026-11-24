<?php

use App\Http\Controllers\API\Crm\PublicCrmUnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
 * Public Sales CRM endpoints: no login. Unsubscribes always work, even if the
 * CRM is switched off later, so a link in an old email is still honoured.
 */
Route::middleware('throttle:60,1,crm-public:')->prefix('public/crm')->name('crm.public.')->group(function () {
    Route::get('unsubscribe/{token}', [PublicCrmUnsubscribeController::class, 'show'])->where('token', '[A-Za-z0-9_\-]+')->name('unsubscribe.show');
    Route::post('unsubscribe/{token}', [PublicCrmUnsubscribeController::class, 'confirm'])->where('token', '[A-Za-z0-9_\-]+')->name('unsubscribe.confirm');
    Route::post('unsubscribe/{token}/one-click', [PublicCrmUnsubscribeController::class, 'oneClick'])->where('token', '[A-Za-z0-9_\-]+')->name('unsubscribe.one-click');
    Route::post('unsubscribe/{token}/resubscribe', [PublicCrmUnsubscribeController::class, 'resubscribe'])->where('token', '[A-Za-z0-9_\-]+')->name('unsubscribe.resubscribe');

    foreach (glob(__DIR__.'/crm-public/*.php') ?: [] as $file) {
        require $file;
    }
});
