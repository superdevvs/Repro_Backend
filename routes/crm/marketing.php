<?php

use App\Http\Controllers\API\Crm\CrmMarketingController;
use Illuminate\Support\Facades\Route;

Route::get('drips', [CrmMarketingController::class, 'drips'])->name('drips.index');
Route::get('drips/{drip}', [CrmMarketingController::class, 'drip'])->whereNumber('drip')->name('drips.show');
Route::put('drips/{drip}', [CrmMarketingController::class, 'saveDrip'])->whereNumber('drip')->name('drips.save');
Route::post('drips/{drip}/publish', [CrmMarketingController::class, 'publishDrip'])->whereNumber('drip')->name('drips.publish');
Route::post('drips/{drip}/status', [CrmMarketingController::class, 'dripStatus'])->whereNumber('drip')->name('drips.status');
Route::get('drips/{drip}/preview', [CrmMarketingController::class, 'previewDrip'])->whereNumber('drip')->name('drips.preview');
Route::post('contacts/{contact}/drip', [CrmMarketingController::class, 'enrol'])->whereNumber('contact')->name('drips.enrol');
Route::post('contacts/{contact}/drip/stop', [CrmMarketingController::class, 'stopDrip'])->whereNumber('contact')->name('drips.stop');
Route::post('contacts/{contact}/replied', [CrmMarketingController::class, 'markReplied'])->whereNumber('contact')->name('contacts.replied');
Route::post('drips/bulk-enrol', [CrmMarketingController::class, 'bulkEnrol'])->name('drips.bulk-enrol');

Route::get('campaigns', [CrmMarketingController::class, 'campaigns'])->name('campaigns.index');
Route::post('campaigns', [CrmMarketingController::class, 'storeCampaign'])->name('campaigns.store');
Route::get('campaigns/{campaign}', [CrmMarketingController::class, 'campaign'])->whereNumber('campaign')->name('campaigns.show');
Route::patch('campaigns/{campaign}', [CrmMarketingController::class, 'updateCampaign'])->whereNumber('campaign')->name('campaigns.update');
Route::post('campaigns/{campaign}/{action}', [CrmMarketingController::class, 'campaignAction'])->whereNumber('campaign')->whereIn('action', ['submit', 'approve', 'send-back', 'schedule'])->name('campaigns.action');
