<?php

use App\Http\Controllers\API\Crm\CrmEmailController;
use App\Http\Controllers\API\Crm\CrmSignatureController;
use App\Http\Controllers\API\Crm\CrmTemplateController;
use Illuminate\Support\Facades\Route;

Route::get('signatures/me', [CrmSignatureController::class, 'me'])->name('signatures.me');
Route::post('signatures/me', [CrmSignatureController::class, 'submit'])->name('signatures.submit');
Route::post('signatures/me/headshot', [CrmSignatureController::class, 'headshot'])->middleware('throttle:20,1,crm-headshot:')->name('signatures.headshot');
Route::get('signatures/pending', [CrmSignatureController::class, 'pending'])->name('signatures.pending');
Route::post('signatures/{signature}/approve', [CrmSignatureController::class, 'approve'])->whereNumber('signature')->name('signatures.approve');
Route::post('signatures/{signature}/reject', [CrmSignatureController::class, 'reject'])->whereNumber('signature')->name('signatures.reject');

Route::get('templates', [CrmTemplateController::class, 'index'])->name('templates.index');
Route::post('templates', [CrmTemplateController::class, 'store'])->name('templates.store');
Route::post('templates/preview', [CrmTemplateController::class, 'preview'])->name('templates.preview');
Route::get('templates/{template}', [CrmTemplateController::class, 'show'])->whereNumber('template')->name('templates.show');
Route::put('templates/{template}', [CrmTemplateController::class, 'update'])->whereNumber('template')->name('templates.update');
Route::post('templates/{template}/submit', [CrmTemplateController::class, 'submit'])->whereNumber('template')->name('templates.submit');
Route::post('templates/{template}/retire', [CrmTemplateController::class, 'retire'])->whereNumber('template')->name('templates.retire');
Route::post('template-versions/{version}/approve', [CrmTemplateController::class, 'approve'])->whereNumber('version')->name('template-versions.approve');
Route::post('template-versions/{version}/reject', [CrmTemplateController::class, 'reject'])->whereNumber('version')->name('template-versions.reject');

Route::get('contacts/{contact}/emails', [CrmEmailController::class, 'sends'])->whereNumber('contact')->name('emails.index');
Route::get('contacts/{contact}/emails/compose', [CrmEmailController::class, 'composeOptions'])->whereNumber('contact')->name('emails.compose');
Route::post('contacts/{contact}/emails/preview', [CrmEmailController::class, 'preview'])->whereNumber('contact')->name('emails.preview');
Route::post('contacts/{contact}/emails', [CrmEmailController::class, 'send'])->whereNumber('contact')->middleware('throttle:60,1,crm-send:')->name('emails.send');
Route::get('contacts/{contact}/emails/{send}', [CrmEmailController::class, 'showSend'])->whereNumber(['contact', 'send'])->name('emails.show');
