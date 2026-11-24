<?php

use App\Http\Controllers\API\Crm\CrmContactController;
use App\Http\Controllers\API\Crm\CrmImportController;
use App\Http\Controllers\API\Crm\CrmMetaController;
use App\Http\Controllers\API\Crm\CrmNoteController;
use App\Http\Controllers\API\Crm\CrmPipelineController;
use App\Http\Controllers\API\Crm\CrmQueueController;
use Illuminate\Support\Facades\Route;

/*
 * Sales CRM API. Everything here needs CRM_ENABLED, a signed-in user and
 * crm-contacts view. Contacts bind through the owner scope: a sales rep asking
 * for another rep's contact gets 404. There are deliberately no DELETE routes
 * for contacts, notes or applicants.
 */
Route::middleware(['auth:sanctum', 'crm.enabled', 'permission:crm-contacts,view', 'throttle:600,1,crm:'])
    ->prefix('crm')
    ->name('crm.')
    ->group(function () {
        Route::get('meta', [CrmMetaController::class, 'meta'])->name('meta');
        Route::get('notices', [CrmMetaController::class, 'notices'])->name('notices');
        Route::post('notices/read', [CrmMetaController::class, 'readNotices'])->name('notices.read');

        Route::get('pipeline', [CrmPipelineController::class, 'board'])->name('pipeline');
        Route::get('client-pool', [CrmPipelineController::class, 'clientPool'])->name('client-pool');

        Route::get('contacts', [CrmContactController::class, 'index'])->name('contacts.index');
        Route::get('contacts/stats', [CrmMetaController::class, 'contactStats'])->name('contacts.stats');
        Route::post('contacts', [CrmContactController::class, 'store'])->name('contacts.store');
        Route::post('contacts/check-duplicate', [CrmContactController::class, 'checkDuplicate'])->name('contacts.check-duplicate');
        Route::post('contacts/bulk-assign', [CrmContactController::class, 'bulkAssign'])->name('contacts.bulk-assign');
        Route::get('contacts/{contact}', [CrmContactController::class, 'show'])->whereNumber('contact')->name('contacts.show');
        Route::patch('contacts/{contact}', [CrmContactController::class, 'update'])->whereNumber('contact')->name('contacts.update');
        Route::post('contacts/{contact}/stage', [CrmContactController::class, 'stage'])->whereNumber('contact')->name('contacts.stage');
        Route::post('contacts/{contact}/lost', [CrmContactController::class, 'lost'])->whereNumber('contact')->name('contacts.lost');
        Route::post('contacts/{contact}/reopen', [CrmContactController::class, 'reopen'])->whereNumber('contact')->name('contacts.reopen');
        Route::post('contacts/{contact}/first-shoot', [CrmContactController::class, 'firstShoot'])->whereNumber('contact')->name('contacts.first-shoot');
        Route::post('contacts/{contact}/assign', [CrmContactController::class, 'assign'])->whereNumber('contact')->name('contacts.assign');
        Route::get('contacts/{contact}/activity', [CrmContactController::class, 'activity'])->whereNumber('contact')->name('contacts.activity');

        Route::get('contacts/{contact}/notes', [CrmNoteController::class, 'index'])->whereNumber('contact')->name('notes.index');
        Route::post('contacts/{contact}/notes', [CrmNoteController::class, 'store'])->whereNumber('contact')->name('notes.store');
        Route::patch('contacts/{contact}/notes/{note}', [CrmNoteController::class, 'update'])->whereNumber(['contact', 'note'])->name('notes.update');
        Route::get('contacts/{contact}/notes/{note}/versions', [CrmNoteController::class, 'versions'])->whereNumber(['contact', 'note'])->name('notes.versions');

        Route::post('imports/preview', [CrmImportController::class, 'preview'])->middleware('throttle:30,1,crm-import:')->name('imports.preview');
        Route::post('imports/commit', [CrmImportController::class, 'commit'])->middleware('throttle:10,1,crm-import-commit:')->name('imports.commit');

        Route::get('queue/unassigned', [CrmQueueController::class, 'unassigned'])->name('queue.unassigned');
        Route::post('queue/duplicates/{alert}/resolve', [CrmQueueController::class, 'resolveDuplicate'])->whereNumber('alert')->name('queue.duplicates.resolve');

        foreach (glob(__DIR__.'/crm/*.php') ?: [] as $file) {
            require $file;
        }
    });
