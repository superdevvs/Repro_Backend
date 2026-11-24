<?php

use App\Http\Controllers\API\Crm\CrmRecruitingController;
use App\Http\Controllers\API\Crm\CrmRecruitingSetupController;
use Illuminate\Support\Facades\Route;

// Recruiting: super admin only by default; others by a per-user grant.
Route::middleware('permission:crm-recruiting,view')->prefix('recruiting')->name('recruiting.')->group(function () {
    Route::get('meta', [CrmRecruitingController::class, 'meta'])->name('meta');
    Route::get('board', [CrmRecruitingController::class, 'board'])->name('board');
    Route::get('applicants', [CrmRecruitingController::class, 'index'])->name('applicants.index');
    Route::post('applicants', [CrmRecruitingController::class, 'store'])->name('applicants.store');
    Route::get('applicants/{applicant}', [CrmRecruitingController::class, 'show'])->whereNumber('applicant')->name('applicants.show');
    Route::patch('applicants/{applicant}', [CrmRecruitingController::class, 'update'])->whereNumber('applicant')->name('applicants.update');
    Route::post('applicants/{applicant}/stage', [CrmRecruitingController::class, 'stage'])->whereNumber('applicant')->name('applicants.stage');
    Route::post('applicants/{applicant}/exit', [CrmRecruitingController::class, 'exit'])->whereNumber('applicant')->name('applicants.exit');
    Route::post('applicants/{applicant}/reopen', [CrmRecruitingController::class, 'reopen'])->whereNumber('applicant')->name('applicants.reopen');
    Route::post('applicants/{applicant}/hire', [CrmRecruitingController::class, 'hire'])->whereNumber('applicant')->name('applicants.hire');
    Route::post('applicants/{applicant}/notes', [CrmRecruitingController::class, 'note'])->whereNumber('applicant')->name('applicants.notes');
    Route::post('applicants/{applicant}/files', [CrmRecruitingController::class, 'upload'])->whereNumber('applicant')->middleware('throttle:60,1,recruit-upload:')->name('applicants.files');
    Route::post('applicants/{applicant}/events', [CrmRecruitingController::class, 'schedule'])->whereNumber('applicant')->name('applicants.events');
    Route::post('applicants/{applicant}/email', [CrmRecruitingController::class, 'email'])->whereNumber('applicant')->name('applicants.email');
    Route::get('files/{recruitFile}', [CrmRecruitingController::class, 'download'])->whereNumber('recruitFile')->name('files.download');

    Route::get('coverage', [CrmRecruitingSetupController::class, 'coverage'])->name('coverage');
    Route::get('roster', [CrmRecruitingSetupController::class, 'roster'])->name('roster');
    Route::get('postings', [CrmRecruitingSetupController::class, 'postings'])->name('postings.index');
    Route::post('postings', [CrmRecruitingSetupController::class, 'storePosting'])->name('postings.store');
    Route::patch('postings/{posting}', [CrmRecruitingSetupController::class, 'updatePosting'])->whereNumber('posting')->name('postings.update');
    Route::post('postings/{posting}/status', [CrmRecruitingSetupController::class, 'postingStatus'])->whereNumber('posting')->name('postings.status');
});
