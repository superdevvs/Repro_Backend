<?php

use App\Http\Controllers\Admin\AccountingExpenseController;
use App\Http\Controllers\Admin\AccountLinkController;
use App\Http\Controllers\Admin\AccountStatusController;
use App\Http\Controllers\Admin\ComplimentaryReshootController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ServiceAreaController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ServiceGroupController;
use App\Http\Controllers\Admin\TestShootController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\API\Admin\SystemOverviewController;
use App\Http\Controllers\API\AiChatController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AutoenhanceController;
use App\Http\Controllers\API\ClientDeliveryNotificationController;
use App\Http\Controllers\API\ClientEmailVerificationController;
use App\Http\Controllers\API\CouponController;
use App\Http\Controllers\API\CubiCasaController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\EditingRequestController;
use App\Http\Controllers\API\EditorRatesController;
use App\Http\Controllers\API\FeaturedShootController;
use App\Http\Controllers\API\GoogleCalendarController;
use App\Http\Controllers\API\HiggsFieldController;
use App\Http\Controllers\API\IguideOfflineChunkUploadController;
use App\Http\Controllers\API\IguideOfflinePackageController;
use App\Http\Controllers\API\IguideOfflineViewerAssetController;
use App\Http\Controllers\API\IguideOfflineViewerLinkController;
use App\Http\Controllers\API\ImageDownloadController;
use App\Http\Controllers\API\ImageProcessingController;
use App\Http\Controllers\API\IntegrationController;
use App\Http\Controllers\API\IpLocationController;
use App\Http\Controllers\API\LegalDocumentController;
use App\Http\Controllers\API\LinkPreviewController;
use App\Http\Controllers\API\ListingVideoController;
use App\Http\Controllers\API\MediaUploadController;
use App\Http\Controllers\API\Messaging\AutomationController;
use App\Http\Controllers\API\Messaging\ClientConfirmationRecoveryController;
use App\Http\Controllers\API\Messaging\EmailMessagingController;
use App\Http\Controllers\API\Messaging\EmailOpsSummaryController;
use App\Http\Controllers\API\Messaging\MessageTemplateController;
use App\Http\Controllers\API\Messaging\MessagingOverviewController;
use App\Http\Controllers\API\Messaging\MessagingSettingsController;
use App\Http\Controllers\API\Messaging\SmsContactController;
use App\Http\Controllers\API\Messaging\SmsMessagingController;
use App\Http\Controllers\API\Messaging\TelnyxWebhookController;
use App\Http\Controllers\API\OnboardingEventController;
use App\Http\Controllers\API\ProfileSecurityController;
use App\Http\Controllers\API\PublicShootMediaArchiveController;
use App\Http\Controllers\API\PublicShootShareLinkController;
use App\Http\Controllers\API\ReelController;
use App\Http\Controllers\API\ShootController;
use App\Http\Controllers\API\ShootIssuesController;
use App\Http\Controllers\API\ShootMediaController;
use App\Http\Controllers\API\ShootMessageController;
use App\Http\Controllers\API\ShootNotesController;
use App\Http\Controllers\API\ShootPaymentsController;
use App\Http\Controllers\API\ShootPublicAssetsController;
use App\Http\Controllers\API\ShootRescheduleRequestController;
use App\Http\Controllers\API\ShootWorkflowController;
use App\Http\Controllers\API\StudioBrandController;
use App\Http\Controllers\API\StudioDeepLinkController;
use App\Http\Controllers\API\StudioMetricsController;
use App\Http\Controllers\API\StudioProjectController;
use App\Http\Controllers\API\StudioQueueController;
use App\Http\Controllers\API\StudioSearchController;
use App\Http\Controllers\API\StudioSourceController;
use App\Http\Controllers\API\StudioTemplateController;
use App\Http\Controllers\API\SystemEmailHealthController;
use App\Http\Controllers\API\SystemTelemetryController;
use App\Http\Controllers\API\TelnyxAi\TelnyxToolBridgeController;
use App\Http\Controllers\API\TourAnalyticsController;
use App\Http\Controllers\API\UploadSourceController;
use App\Http\Controllers\API\Voice\ScheduledVoiceCallController;
use App\Http\Controllers\API\Voice\VoiceCallController;
use App\Http\Controllers\API\Voice\VoiceCallStreamController;
use App\Http\Controllers\API\Voice\VoiceHandoffController;
use App\Http\Controllers\API\Voice\VoiceHealthController;
use App\Http\Controllers\API\Voice\VoiceLlmUsageController;
use App\Http\Controllers\API\Voice\VoiceMemoryController;
use App\Http\Controllers\API\Voice\VoiceNumberController;
use App\Http\Controllers\API\Voice\VoiceScheduleController;
use App\Http\Controllers\API\Voice\VoiceSettingsController;
use App\Http\Controllers\API\WeatherController;
use App\Http\Controllers\API\Webhooks\TelnyxVoiceWebhookController;
use App\Http\Controllers\API\Webhooks\VapiWebhookController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClientBillingController;
use App\Http\Controllers\DropboxAuthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceReportController;
use App\Http\Controllers\PhotographerAvailabilityController;
use App\Http\Controllers\PhotographerEquipmentController;
use App\Http\Controllers\PhotographerShootController;
use App\Http\Controllers\StripePaymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', [AuthController::class, 'currentUser'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/legal-documents/current', [LegalDocumentController::class, 'current']);
    Route::post('/legal-acceptances', [LegalDocumentController::class, 'accept']);
});

Route::middleware('auth:sanctum')->get('/me/permissions', [PermissionController::class, 'me']);

Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
});

Route::middleware('throttle:120,1')
    ->prefix('public/link-previews')
    ->group(function () {
        $previewTypes = array_merge(
            \App\Services\LinkPreview\LinkPreviewService::TOUR_TYPES,
            \App\Services\LinkPreview\LinkPreviewService::STATIC_TYPES,
        );

        Route::get('{type}', [LinkPreviewController::class, 'metadata'])
            ->whereIn('type', $previewTypes)
            ->name('api.public.link-previews.metadata');
        Route::get('{type}/image/{fingerprint}.jpg', [LinkPreviewController::class, 'image'])
            ->whereIn('type', $previewTypes)
            ->where('fingerprint', '[a-f0-9]{16}')
            ->name('api.public.link-previews.image');
    });

Route::get('/v1/featured-shoot', FeaturedShootController::class)
    ->middleware('throttle:120,1')
    ->name('api.v1.featured-shoot');

Route::get('/v1/featured-shoots', [FeaturedShootController::class, 'index'])
    ->middleware('throttle:120,1')
    ->name('api.v1.featured-shoots');

Route::middleware('telnyx.toolbridge')
    ->prefix('telnyx-ai/tools')
    ->group(function () {
        Route::post('{tool}', [TelnyxToolBridgeController::class, 'invoke'])
            ->whereIn('tool', \App\Services\TelnyxAi\ToolBridgeRegistry::ALLOWED_TOOLS)
            ->name('telnyx-ai.tools.invoke');
    });

Route::post('/webhooks/telnyx/voice', TelnyxVoiceWebhookController::class);
Route::post('/webhooks/vapi', VapiWebhookController::class);

Route::middleware(['auth:sanctum', 'permission:voice-calls'])->prefix('voice')->group(function () {
    Route::get('calls', [VoiceCallController::class, 'index']);
    Route::get('calls/stats', [VoiceCallController::class, 'stats']);
    Route::get('calls/{call}', [VoiceCallController::class, 'show']);
    Route::get('calls/{call}/transcript', [VoiceCallController::class, 'transcript']);
    Route::get('calls/{call}/recording-url', [VoiceCallController::class, 'recordingUrl']);
    Route::get('calls/{call}/stream', VoiceCallStreamController::class);
    Route::get('calls/{call}/memory', [VoiceMemoryController::class, 'show']);
    Route::post('calls/{call}/memory/load-full', [VoiceMemoryController::class, 'loadFull']);
    Route::post('calls/{call}/cockpit-opened', [VoiceCallController::class, 'cockpitOpened']);
    Route::post('calls/outbound', [VoiceCallController::class, 'outbound']);
    Route::post('calls/{call}/hangup', [VoiceCallController::class, 'hangup']);
    Route::post('calls/{call}/page-staff', [VoiceCallController::class, 'pageStaff']);
    Route::get('health', VoiceHealthController::class);
    Route::get('schedule/state', [VoiceScheduleController::class, 'state']);
    Route::get('schedule/overrides', [VoiceScheduleController::class, 'index']);
    Route::post('schedule/overrides', [VoiceScheduleController::class, 'store']);
    Route::delete('schedule/overrides/{override}', [VoiceScheduleController::class, 'destroy']);
    Route::get('llm-usage', VoiceLlmUsageController::class);
    Route::get('scheduled-calls', [ScheduledVoiceCallController::class, 'index']);
    Route::post('scheduled-calls', [ScheduledVoiceCallController::class, 'store']);
    Route::patch('scheduled-calls/{scheduledCall}', [ScheduledVoiceCallController::class, 'update']);
    Route::post('scheduled-calls/{scheduledCall}/cancel', [ScheduledVoiceCallController::class, 'cancel']);
    Route::post('scheduled-calls/{scheduledCall}/retry', [ScheduledVoiceCallController::class, 'retry']);
    Route::get('numbers', [VoiceNumberController::class, 'index']);
    Route::patch('numbers/{smsNumber}', [VoiceNumberController::class, 'update']);
    Route::get('handoffs/recent', [VoiceHandoffController::class, 'index']);
    Route::get('settings', [VoiceSettingsController::class, 'show']);
    Route::patch('settings', [VoiceSettingsController::class, 'update']);
});

Route::get('/weather', [WeatherController::class, 'show'])
    ->middleware('throttle:300,1');

Route::get('/ip-location', [IpLocationController::class, 'show'])
    ->middleware('throttle:300,1');

Route::get('/public/share-links/{token}', [PublicShootShareLinkController::class, 'show'])
    ->name('api.public.share-links.show');
Route::get('/public/share-links/{token}/download', [PublicShootShareLinkController::class, 'download'])
    ->name('api.public.share-links.download');
Route::get('/public/shoot-media/{shoot}/download-zip', [PublicShootMediaArchiveController::class, 'show'])
    ->middleware('signed')
    ->name('api.public.shoot-media.download');

// Short-lived bearer route used by the manual iGUIDE viewer. The signature is
// deliberately a path segment so index.html's relative asset requests retain it.
Route::get('/iguide/offline-view/{shootId}/{fileId}/{expires}/{signature}/{path?}', IguideOfflineViewerAssetController::class)
    ->whereNumber('shootId')
    ->whereNumber('fileId')
    ->whereNumber('expires')
    ->where('signature', '[a-f0-9]{64}')
    ->where('path', '.*')
    ->name('api.public.iguide-offline-viewer.asset');

$shootMediaCorsPreflight = function (Request $request) {
    $origin = $request->headers->get('Origin', '*');

    return response()->noContent()
        ->header('Access-Control-Allow-Origin', $origin)
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
        ->header('Access-Control-Allow-Credentials', 'true');
};

Route::options('/shoots/{shoot}/editor-download-raw', $shootMediaCorsPreflight);
Route::options('/shoots/{shoot}/generate-share-link', $shootMediaCorsPreflight);

// AI Chat health check (no auth required)
Route::get('/ai/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Robbie AI Chat',
        'timestamp' => now()->toIso8601String(),
        'routes_loaded' => true,
    ]);
});

Route::prefix('dropbox')->name('dropbox.')->group(function () {
    // Studio connection controls require explicit administrator authentication.
    Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->group(function () {
        Route::get('config', [DropboxAuthController::class, 'getConfig'])->name('config');
        Route::post('connect', [DropboxAuthController::class, 'connect'])->middleware('throttle:5,1')->name('connect');
        Route::post('disconnect', [DropboxAuthController::class, 'disconnect'])->middleware('throttle:5,1')->name('disconnect');
    });

    // Provider callbacks use one-use browser-bound state or provider signatures.
    Route::get('callback', [DropboxAuthController::class, 'callback'])->name('callback');
    Route::match(['get', 'post'], 'webhook', [DropboxAuthController::class, 'webhook'])->name('webhook');
});

Route::get('google-calendar/callback', [GoogleCalendarController::class, 'callback'])
    ->name('google-calendar.callback');
Route::get('upload-sources/{provider}/callback', [UploadSourceController::class, 'callback'])
    ->whereIn('provider', \App\Services\UploadSourceService::PROVIDERS)
    ->name('upload-sources.callback');

// Stripe Webhook (no auth - signature verified in controller)
Route::post('webhooks/stripe', [StripePaymentController::class, 'handleWebhook'])
    ->name('webhooks.stripe');

Route::get('public/payments/{token}', [ShootPaymentsController::class, 'getPublicPaymentDetails'])
    ->name('api.public.payments.show');
Route::post('public/payments/{token}/checkout', [StripePaymentController::class, 'createPublicEmbeddedCheckoutSession'])
    ->name('api.public.payments.checkout');
Route::post('public/payments/{token}/confirm', [StripePaymentController::class, 'confirmPublicCheckoutSession'])
    ->name('api.public.payments.confirm');

// Telnyx SMS Webhooks (no auth - Ed25519 signature verification handled in controller)
// Single canonical endpoint configured on the Messaging Profile; routes by data.event_type.
Route::post('webhooks/telnyx/messaging', [TelnyxWebhookController::class, 'messaging'])
    ->name('webhooks.telnyx.messaging');
// Reserved for explicit per-message webhook_url overrides; not configured by default.
Route::post('webhooks/telnyx/status', [TelnyxWebhookController::class, 'status'])
    ->name('webhooks.telnyx.status');

// Cakemail Email Webhooks (no auth - webhook verification handled in controller)
Route::match(['get', 'post'], 'webhooks/cakemail', [App\Http\Controllers\API\CakemailWebhookController::class, 'handle'])
    ->name('webhooks.cakemail');

Route::get('email/verify/{user}/{hash}', ClientEmailVerificationController::class)
    ->name('api.email-verification.verify');

// MMM Punchout return callback (public endpoint)
Route::post('integrations/mmm/return', [IntegrationController::class, 'mmmReturn'])
    ->name('integrations.mmm.return');

// External Booking API (for Lovable / third-party sites)
// Secured via X-API-Key header, no user login required
Route::middleware('external_api_key')->prefix('external')->group(function () {
    Route::post('/book-shoot', [App\Http\Controllers\API\ExternalBookingController::class, 'bookShoot'])
        ->name('external.book-shoot');
    Route::post('/check-client', [App\Http\Controllers\API\ExternalBookingController::class, 'checkClient'])
        ->name('external.check-client');
    Route::get('/services', [App\Http\Controllers\API\ExternalBookingController::class, 'services'])
        ->name('external.services');
});

// Legacy Dropbox setup/debug HTTP helpers are retired in every environment.
// Use the authenticated studio connection flow; operational diagnostics stay CLI-only.

Route::prefix('address')->group(function () {
    Route::get('search', [App\Http\Controllers\AddressLookupController::class, 'searchAddresses']);
    Route::get('details', [App\Http\Controllers\AddressLookupController::class, 'getAddressDetails']);
    Route::post('validate', [App\Http\Controllers\AddressLookupController::class, 'validateAddress']);
    Route::post('geocode', [App\Http\Controllers\AddressLookupController::class, 'geocodeAddress'])
        ->middleware('throttle:120,1');
    Route::post('distance', [App\Http\Controllers\AddressLookupController::class, 'calculateDistance']);
    Route::get('service-area', [App\Http\Controllers\AddressLookupController::class, 'checkServiceArea']);
    Route::get('nearby-photographers', [App\Http\Controllers\AddressLookupController::class, 'getNearbyPhotographers']);

    // Address provider settings (admin only)
    Route::middleware('auth:sanctum')->prefix('provider')->group(function () {
        Route::get('/', [App\Http\Controllers\API\AddressProviderSettingsController::class, 'getProvider']);
        Route::put('/', [App\Http\Controllers\API\AddressProviderSettingsController::class, 'updateProvider']);
    });
});

// Group of routes that require user authentication (e.g., using Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('role:photographer,admin,superadmin,editing_manager')->post('/google-calendar/connect', [GoogleCalendarController::class, 'connect']);
    Route::middleware('role:photographer,admin,superadmin,editing_manager')->get('/google-calendar/status', [GoogleCalendarController::class, 'status']);
    Route::middleware('role:photographer')->prefix('google-calendar')->group(function () {
        Route::delete('/disconnect', [GoogleCalendarController::class, 'disconnect']);
        Route::post('/resync', [GoogleCalendarController::class, 'resync']);
    });
    Route::middleware('role:admin,superadmin,editing_manager')->get('/admin/google-calendar/overview', [GoogleCalendarController::class, 'adminOverview']);

    // Legacy compatibility route: existing UI still calls create-checkout-link,
    // but checkout is now handled by Stripe.
    Route::post('shoots/{shoot}/create-checkout-link', [StripePaymentController::class, 'createCheckoutSession'])
        ->name('api.shoots.payment.create-link');
    Route::post('shoots/{shoot}/create-stripe-checkout', [StripePaymentController::class, 'createCheckoutSession'])
        ->name('api.shoots.stripe.checkout');
    Route::post('shoots/{shoot}/create-stripe-embedded-checkout', [StripePaymentController::class, 'createEmbeddedCheckoutSession'])
        ->name('api.shoots.stripe.embedded-checkout');
    Route::post('shoots/{shoot}/confirm-stripe-session', [StripePaymentController::class, 'confirmCheckoutSession'])
        ->name('api.shoots.stripe.confirm-session');
    Route::get('shoots/{shoot}/payment-details', [ShootPaymentsController::class, 'getPaymentDetails'])
        ->name('api.shoots.payment-details');

    // Offline (cash/cheque) payment intents: clients/admins/reps create; admins/reps confirm or decline
    Route::post('shoots/{shoot}/payment-intents', [ShootPaymentsController::class, 'createIntent'])
        ->name('api.shoots.payment-intents.create');
    Route::post('shoots/{shoot}/payment-intents/{payment}/confirm', [ShootPaymentsController::class, 'confirmIntent'])
        ->name('api.shoots.payment-intents.confirm');
    Route::post('shoots/{shoot}/payment-intents/{payment}/decline', [ShootPaymentsController::class, 'declineIntent'])
        ->name('api.shoots.payment-intents.decline');

    // Legacy compatibility route for older multi-pay callers.
    Route::post('payments/multiple-shoots', [StripePaymentController::class, 'payMultipleShoots'])
        ->name('api.payments.multiple-shoots');

    // Stripe: Pay for multiple shoots
    Route::post('payments/stripe-multiple-shoots', [StripePaymentController::class, 'payMultipleShoots'])
        ->name('api.payments.stripe-multiple-shoots');

    // Stripe: Embedded checkout for multiple shoots
    Route::post('payments/stripe-multiple-shoots-embedded', [StripePaymentController::class, 'payMultipleShootsEmbedded'])
        ->name('api.payments.stripe-multiple-shoots-embedded');

    // Stripe: exact-session browser return/reconciliation for single or bulk payments
    Route::post('payments/stripe-session/confirm', [StripePaymentController::class, 'confirmPaymentSession'])
        ->name('api.payments.stripe-session.confirm');

    // Stripe refund
    Route::post('payments/stripe-refund', [StripePaymentController::class, 'refundPayment'])
        ->middleware('role:admin,superadmin')
        ->name('api.payments.stripe-refund');

});

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login']);

// Password Reset Routes (public)
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [AuthController::class, 'resetPasswordWithToken']);

Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
Route::middleware('auth:sanctum')->post('/system-telemetry/events', [SystemTelemetryController::class, 'store']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->get('/system/email-health', SystemEmailHealthController::class);

// Self profile update (authenticated user updates their own profile)
Route::middleware('auth:sanctum')->put('/profile', [AuthController::class, 'updateProfile']);
Route::middleware('auth:sanctum')->post('/profile/email-verification/resend', [AuthController::class, 'resendEmailVerification']);
Route::middleware('auth:sanctum')->post('/profile/email-verification/correct', [AuthController::class, 'correctVerificationEmail']);
Route::middleware('auth:sanctum')->post('/profile/tax-document', [App\Http\Controllers\API\TaxDocumentController::class, 'store']);
Route::middleware('auth:sanctum')->get('/profile/tax-document', [App\Http\Controllers\API\TaxDocumentController::class, 'show']);
Route::middleware('auth:sanctum')->get('/profile/tax-document/download', [App\Http\Controllers\API\TaxDocumentController::class, 'download']);
Route::middleware('auth:sanctum')->get('/admin/users/{user}/tax-document', [App\Http\Controllers\API\TaxDocumentController::class, 'showForUser'])->whereNumber('user');
Route::middleware('auth:sanctum')->get('/admin/users/{user}/tax-document/download', [App\Http\Controllers\API\TaxDocumentController::class, 'downloadForUser'])->whereNumber('user');
Route::middleware('auth:sanctum')->prefix('profile')->group(function () {
    Route::get('/activity', [ProfileSecurityController::class, 'activity']);
    Route::get('/security', [ProfileSecurityController::class, 'status']);
    Route::post('/security/two-factor/setup', [ProfileSecurityController::class, 'beginTwoFactorSetup']);
    Route::post('/security/two-factor/confirm', [ProfileSecurityController::class, 'confirmTwoFactorSetup']);
    Route::post('/security/two-factor/recovery-codes', [ProfileSecurityController::class, 'regenerateRecoveryCodes']);
    Route::delete('/security/two-factor', [ProfileSecurityController::class, 'disableTwoFactor']);
    Route::delete('/security/sessions/others', [ProfileSecurityController::class, 'revokeOtherSessions']);
    Route::delete('/security/sessions/{tokenId}', [ProfileSecurityController::class, 'revokeSession'])->whereNumber('tokenId');
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->get('/admin/users', [UserController::class, 'index']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->get('/admin/permissions', [PermissionController::class, 'index']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->put('/admin/permissions', [PermissionController::class, 'update']);

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->patch('/admin/users/{id}/role', [UserController::class, 'updateRole']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->patch('/admin/users/{user}/convert-type', [AccountStatusController::class, 'convertType']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->patch('/admin/users/{user}/status', [AccountStatusController::class, 'setStatus'])->withTrashed();
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->get('/admin/users/deleted-accounts', [AccountStatusController::class, 'deletedAccounts']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->get('/admin/users/{id}', [UserController::class, 'show'])->whereNumber('id');
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->post('/admin/users/{user}/restore', [AccountStatusController::class, 'restore'])->withTrashed();
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->patch('/admin/users/{id}/password', [UserController::class, 'resetPassword']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->post('/admin/users/{id}/send-reset-link', [UserController::class, 'sendResetLink']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->post('/admin/users/{id}/resend-verification', [UserController::class, 'resendVerificationEmail']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->post('/admin/users/{id}/address-change/approve', [UserController::class, 'approvePhotographerAddressChange']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->post('/admin/users/{id}/address-change/reject', [UserController::class, 'rejectPhotographerAddressChange']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->put('/admin/users/{id}', [UserController::class, 'update']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->delete('/admin/users/{id}', [UserController::class, 'destroy']);
Route::middleware(['auth:sanctum', 'role:admin,superadmin,salesRep'])->post('/admin/users', [UserController::class, 'store']);

Route::middleware(['auth:sanctum', 'role:superadmin'])->prefix('admin/system-overview')->group(function () {
    Route::get('/snapshot', [SystemOverviewController::class, 'snapshot']);
    Route::get('/history', [SystemOverviewController::class, 'history']);
    Route::get('/users/live', [SystemOverviewController::class, 'liveUsers']);
    Route::get('/routes', [SystemOverviewController::class, 'routes']);
    Route::get('/traces/{traceId}', [SystemOverviewController::class, 'trace']);
});

// Account Linking Routes - Admin endpoints
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->group(function () {
    Route::get('/admin/account-links', [AccountLinkController::class, 'index']);
    Route::post('/admin/account-links', [AccountLinkController::class, 'store']);
    Route::post('/admin/account-links/batch', [AccountLinkController::class, 'batchStore']);
    Route::patch('/admin/account-links/{id}', [AccountLinkController::class, 'update']);
    Route::delete('/admin/account-links/{id}/permanent', [AccountLinkController::class, 'forceDestroy']);
    Route::delete('/admin/account-links/{id}', [AccountLinkController::class, 'destroy']);
    Route::get('/admin/account-links/shared-data/{accountId}', [AccountLinkController::class, 'getSharedData']);
    Route::get('/admin/account-links/available-accounts', [AccountLinkController::class, 'getAvailableAccounts']);
});

// Account Linking Routes - User-facing endpoints (accessible to all authenticated users)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/account-links/has-linked', [AccountLinkController::class, 'hasLinkedAccounts']);
    Route::get('/account-links/my-linked-accounts', [AccountLinkController::class, 'getLinkedAccountsForUser']);
    Route::get('/account-links/my-shared-data', [AccountLinkController::class, 'getMySharedData']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->get('/admin/clients', [UserController::class, 'getClients']);

// Inactive-client report (feature #9) — admins see all clients, sales reps see their own.
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->get('/admin/reports/inactive-clients', [App\Http\Controllers\SalesReportController::class, 'inactiveClientsReport']);

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,client,salesRep'])->get('/admin/photographers', [UserController::class, 'getPhotographers']);
// Public lightweight list for dropdowns
Route::get('/photographers', [UserController::class, 'simplePhotographers']);

// Photographer service-area assignment tool (Req 10) — assign / filter / preview / commit.
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->group(function () {
    Route::post('/admin/photographers/{user}/service-areas', [ServiceAreaController::class, 'assign']);
    Route::get('/admin/service-area/photographers', [ServiceAreaController::class, 'filter']);
    Route::post('/admin/assignments/preview', [ServiceAreaController::class, 'preview']);
    Route::post('/admin/assignments/commit', [ServiceAreaController::class, 'commit']);

    // Test_Shoot generator/simulator (Req 10.7-10.9) — create / preview eligible / assign.
    Route::post('/admin/test-shoots', [TestShootController::class, 'createTestShoot']);
    Route::get('/admin/test-shoots/{shoot}/eligible-photographers', [TestShootController::class, 'previewEligible']);
    Route::post('/admin/test-shoots/{shoot}/assign', [TestShootController::class, 'assignTestShoot']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->prefix('admin/photographer-equipments')->group(function () {
    Route::get('/', [PhotographerEquipmentController::class, 'adminIndex']);
    Route::post('/', [PhotographerEquipmentController::class, 'adminStore']);
    Route::put('/{equipmentId}', [PhotographerEquipmentController::class, 'adminUpdate']);
    Route::delete('/{equipmentId}', [PhotographerEquipmentController::class, 'adminDestroy']);
    Route::post('/{equipmentId}/photos', [PhotographerEquipmentController::class, 'adminUploadPhotos']);
    Route::post('/{equipmentId}/approve', [PhotographerEquipmentController::class, 'approve']);
    Route::post('/{equipmentId}/reject', [PhotographerEquipmentController::class, 'reject']);
    Route::post('/{equipmentId}/send-verification-email', [PhotographerEquipmentController::class, 'sendVerificationEmail']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->prefix('admin/accounting-expenses')->group(function () {
    Route::get('/', [AccountingExpenseController::class, 'index']);
    Route::post('/', [AccountingExpenseController::class, 'store']);
    Route::get('/{expense}', [AccountingExpenseController::class, 'show']);
    Route::put('/{expense}', [AccountingExpenseController::class, 'update']);
    Route::delete('/{expense}', [AccountingExpenseController::class, 'destroy']);
    Route::post('/{expense}/receipt', [AccountingExpenseController::class, 'uploadReceipt']);
    Route::get('/{expense}/receipt', [AccountingExpenseController::class, 'showReceipt']);
});

Route::middleware(['auth:sanctum', 'role:photographer'])->prefix('photographer/equipments')->group(function () {
    Route::get('/', [PhotographerEquipmentController::class, 'photographerIndex']);
    Route::post('/{equipmentId}/verification-photos', [PhotographerEquipmentController::class, 'photographerUploadVerificationPhotos']);
});

Route::middleware('auth:sanctum')->get(
    '/photographer-equipments/{equipmentId}/photos/{photoId}',
    [PhotographerEquipmentController::class, 'showPhoto']
);

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->get('/admin/service-groups', [ServiceGroupController::class, 'index']);

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->group(function () {
    Route::get('/admin/services', [ServiceController::class, 'index']);

    Route::post('/admin/services', [ServiceController::class, 'store']);

    Route::put('/admin/services/{id}', [ServiceController::class, 'update']);

    Route::delete('/admin/services/{id}', [ServiceController::class, 'destroy']);

    Route::post('/admin/service-groups', [ServiceGroupController::class, 'store']);
    Route::put('/admin/service-groups/{serviceGroup}', [ServiceGroupController::class, 'update']);
    Route::delete('/admin/service-groups/{serviceGroup}', [ServiceGroupController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->get(
    '/dashboard/overview',
    [DashboardController::class, 'overview']
);

// Lightweight schedule snapshot used by the editor dashboard to indicate
// platform-wide incoming work pressure. Counts only — no PII.
Route::middleware(['auth:sanctum', 'role:editor,admin,superadmin,editing_manager'])->get(
    '/dashboard/schedule-summary',
    [DashboardController::class, 'scheduleSummary']
);

// Role-based notifications endpoint - accessible to all authenticated users
Route::middleware(['auth:sanctum'])->get(
    '/notifications',
    [DashboardController::class, 'notifications']
);

// Robbie Insights - dynamic, context-aware insights for all authenticated users
Route::middleware(['auth:sanctum'])->get(
    '/robbie/insights',
    [DashboardController::class, 'robbieInsights']
);

// General invoices route - accessible to all authenticated users (role-based filtering in controller)
Route::middleware('auth:sanctum')->prefix('invoices')->group(function () {
    Route::get('/', [InvoiceController::class, 'index']);
    Route::get('{invoice}/download', [InvoiceController::class, 'download']);
});

Route::middleware('auth:sanctum')->prefix('client')->group(function () {
    Route::get('billing', [ClientBillingController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->prefix('admin')->group(function () {
    Route::middleware('role:admin,superadmin')->group(function () {
        Route::get('shoots/{sourceShoot}/complimentary-reshoots', [ComplimentaryReshootController::class, 'template']);
        Route::get('shoots/{sourceShoot}/complimentary-reshoots/template', [ComplimentaryReshootController::class, 'template']);
        Route::get('shoots/{sourceShoot}/complimentary-reshoot-template', [ComplimentaryReshootController::class, 'template']);
        Route::post('shoots/{sourceShoot}/complimentary-reshoots', [ComplimentaryReshootController::class, 'store']);
        Route::patch('shoots/{shoot}/compensations', [ComplimentaryReshootController::class, 'update']);
        Route::post(
            'shoots/{shoot}/compensations/{compensation}/adjustments',
            [ComplimentaryReshootController::class, 'storeAdjustment']
        );
    });

    Route::get('invoices', [InvoiceController::class, 'index']);
    // Static routes MUST come before the {invoice} wildcard to avoid being swallowed
    Route::post('invoices/generate', [App\Http\Controllers\Admin\InvoiceController::class, 'generate']);
    Route::get('invoices/pending-approval', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'pending']);
    Route::get('invoices/review-queue', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'reviewQueue']);
    // Wildcard routes
    Route::get('invoices/{invoice}/download', [InvoiceController::class, 'download']);
    Route::get('invoices/{invoice}/review-detail', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'reviewDetail']);
    Route::get('invoices/{invoice}', [App\Http\Controllers\Admin\InvoiceController::class, 'show']);
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);
    Route::patch('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);
    Route::post('invoices/{invoice}/misc-items', [App\Http\Controllers\Admin\InvoiceController::class, 'addMiscItem']);
    Route::match(['put', 'patch'], 'invoices/{invoice}/misc-items/{item}', [App\Http\Controllers\Admin\InvoiceController::class, 'updateMiscItem']);
    Route::delete('invoices/{invoice}/misc-items/{item}', [App\Http\Controllers\Admin\InvoiceController::class, 'removeMiscItem']);

    // Manual payment reminder (meeting 26 Jul 2026, [00:15:28]). Sales reps and
    // editing managers chase payment too, so this is not admin-only; the parent
    // group already restricts to admin/superadmin/editing_manager and the
    // salesRep case is registered separately below.
    Route::post('invoices/{invoice}/send-reminder', [App\Http\Controllers\Admin\InvoiceReminderController::class, 'send']);

    // Invoice approval endpoints
    Route::post('invoices/{invoice}/approve', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'approve']);
    Route::post('invoices/{invoice}/reject', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'reject']);

    // Payout report endpoints
    Route::get('payout-report', [App\Http\Controllers\PayoutReportController::class, 'index']);
    Route::get('payout-report/download', [App\Http\Controllers\PayoutReportController::class, 'download']);
    Route::post('payout-report/send', [App\Http\Controllers\PayoutReportController::class, 'send']);

    // Editor payout endpoints
    Route::get('editors/earnings', [App\Http\Controllers\Admin\EditorPayoutController::class, 'index']);
    Route::get('editors/{editor}/earnings-detail', [App\Http\Controllers\Admin\EditorPayoutController::class, 'detail']);
    Route::post('editors/payouts/mark-paid', [App\Http\Controllers\Admin\EditorPayoutController::class, 'markPaid']);
    Route::get('editors/reports', [App\Http\Controllers\Admin\EditorPayoutController::class, 'report']);
    Route::post('editors/reports/send', [App\Http\Controllers\Admin\EditorPayoutController::class, 'sendReport']);

    // Sales report endpoints
    Route::get('sales-reports/{salesRepId}', [App\Http\Controllers\SalesReportController::class, 'salesRepReport']);
    Route::post('sales-reports/send-weekly', [App\Http\Controllers\SalesReportController::class, 'sendWeeklyReports']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,photographer,salesRep,sales_rep'])
    ->get('/admin/shoots/{shoot}/compensations', [ComplimentaryReshootController::class, 'show']);

// Tour Branding routes (Admin/Super Admin only)
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->prefix('tour-branding')->group(function () {
    Route::get('/', [App\Http\Controllers\API\TourBrandingController::class, 'index']);
    Route::post('/', [App\Http\Controllers\API\TourBrandingController::class, 'store']);
    Route::put('/{id}', [App\Http\Controllers\API\TourBrandingController::class, 'update']);
    Route::delete('/{id}', [App\Http\Controllers\API\TourBrandingController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    // User branding routes
    Route::get('/users/{user}/branding', [App\Http\Controllers\API\UserBrandingController::class, 'show']);
    Route::put('/users/{user}/branding', [App\Http\Controllers\API\UserBrandingController::class, 'update']);

    // Shoot management
    Route::get('/shoots', [ShootController::class, 'index']);
    Route::post('/shoots', [ShootController::class, 'store']);
    // History routes must come before /shoots/{shoot} to avoid route conflict
    Route::get('/shoots/history', [ShootController::class, 'history']);
    Route::get('/shoots/history/export', [ShootController::class, 'exportHistory']);
    // Pending cancellations must come before /shoots/{shoot} to avoid route conflict
    Route::get('/shoots/pending-cancellations', [ShootWorkflowController::class, 'pendingCancellations']);
    Route::get('/shoots/{shoot}', [ShootController::class, 'show']);
    Route::get('/shoots/{shoot}/invoice', [ShootPaymentsController::class, 'getOrCreateInvoice']);
    // Minimal update endpoint for status/workflow updates
    Route::patch('/shoots/{shoot}', [ShootController::class, 'update']);
    // Mark shoot as paid (Admin and Super Admin)
    Route::post('/shoots/{shoot}/mark-paid', [ShootPaymentsController::class, 'markAsPaid'])->middleware('role:admin,superadmin');
    // State transition endpoints
    Route::post('/shoots/{shoot}/schedule', [ShootController::class, 'schedule']);
    Route::post('/shoots/{shoot}/assign-editor', [ShootWorkflowController::class, 'assignEditor'])
        ->middleware('role:admin,superadmin,editing_manager');
    Route::post('/shoots/{shoot}/start-editing', [ShootWorkflowController::class, 'startEditing']);
    Route::post('/shoots/{shoot}/ready-for-review', [ShootWorkflowController::class, 'readyForReview']);
    Route::post('/shoots/{shoot}/complete', [ShootWorkflowController::class, 'complete']);
    Route::post('/shoots/{shoot}/put-on-hold', [ShootWorkflowController::class, 'putOnHold']);
    Route::post('/shoots/{shoot}/approve', [ShootController::class, 'approve']);
    Route::post('/shoots/{shoot}/decline', [ShootWorkflowController::class, 'decline']);

    // Per-service photographer assignment (multi-photographer per shoot)
    Route::post('/shoots/{shoot}/assign-service-photographer', [ShootController::class, 'assignServicePhotographer']);
    Route::post('/shoots/{shoot}/assign-service-photographers', [ShootController::class, 'assignServicePhotographers']);

    // Apply stored alternate date to the live schedule (internal update, no notifications)
    Route::post('/shoots/{shoot}/apply-alternate-date', [ShootController::class, 'applyAlternateDate'])
        ->middleware('role:admin,superadmin,editing_manager');

    // Cancellation request endpoints
    Route::post('/shoots/{shoot}/request-cancellation', [ShootWorkflowController::class, 'requestCancellation']);
    Route::post('/shoots/{shoot}/withdraw-request', [ShootWorkflowController::class, 'withdrawRequested']);
    Route::post('/shoots/{shoot}/approve-cancellation', [ShootWorkflowController::class, 'approveCancellation']);
    Route::post('/shoots/{shoot}/reject-cancellation', [ShootWorkflowController::class, 'rejectCancellation']);
    // Hold request endpoints
    Route::post('/shoots/{shoot}/request-hold', [ShootWorkflowController::class, 'requestHold']);
    Route::post('/shoots/{shoot}/approve-hold', [ShootWorkflowController::class, 'approveHold']);
    Route::post('/shoots/{shoot}/reject-hold', [ShootWorkflowController::class, 'rejectHold']);
    // Direct cancel endpoint for admin use
    Route::post('/shoots/{shoot}/cancel', [ShootWorkflowController::class, 'cancel'])->middleware('role:admin,superadmin');

    // Photographer availability
    Route::get('/photographers/{id}/availability', [ShootController::class, 'getPhotographerAvailability']);

    // Media albums
    Route::post('/shoots/{shoot}/albums', [ShootMediaController::class, 'createAlbum']);
    Route::get('/shoots/{shoot}/albums', [ShootMediaController::class, 'listAlbums']);
    Route::post('/shoots/{shoot}/media', [ShootMediaController::class, 'uploadMedia']);

    // Notes
    Route::get('/shoots/{shoot}/notes', [ShootNotesController::class, 'getNotes']);
    Route::post('/shoots/{shoot}/notes', [ShootNotesController::class, 'storeNote']);
    Route::patch('/shoots/{shoot}/notes', [ShootNotesController::class, 'updateNotesSimple']);

    Route::get('/client/delivery-notifications', [ClientDeliveryNotificationController::class, 'index']);
    Route::post('/client/delivery-notifications/{notification}/seen', [ClientDeliveryNotificationController::class, 'seen']);

    // Activity Log
    Route::get('/shoots/{shoot}/activity-log', [ShootNotesController::class, 'getActivityLog']);
    Route::delete('/shoots/{shoot}', [ShootController::class, 'destroy'])->middleware('role:admin,superadmin,editing_manager');

    // File workflow endpoints
    Route::post('/shoots/{shoot}/upload', [ShootMediaController::class, 'uploadFiles']);
    Route::post('/shoots/{shoot}/upload-from-source', [UploadSourceController::class, 'import']);
    Route::post('/shoots/{shoot}/upload/finalize-raw', [ShootMediaController::class, 'finalizeRawUpload']);
    Route::post('/shoots/{shoot}/upload/finalize-edited', [ShootMediaController::class, 'finalizeEditedUpload']);
    Route::post('/shoots/{shoot}/approve-editing-review', [ShootMediaController::class, 'approveEditingReview']);
    Route::post('/shoots/{shoot}/upload-extra', [ShootMediaController::class, 'uploadExtra']);
    Route::get('/shoots/{shoot}/files', [ShootMediaController::class, 'getFiles']);
    Route::post('/shoots/{shoot}/files/download', [ShootMediaController::class, 'downloadSelectedFiles']);
    Route::get('/shoots/{shoot}/files/{file}/preview', [ShootMediaController::class, 'previewFile']);
    Route::get('/shoots/{shoot}/media', [ShootMediaController::class, 'listMedia']);
    Route::get('/shoots/{shoot}/media/download-zip', [ShootMediaController::class, 'downloadMediaZip']);
    Route::get('/shoots/{shoot}/editor-download-raw', [ShootMediaController::class, 'editorDownloadRaw'])->middleware('role:editor,admin,superadmin,editing_manager');
    Route::post('/shoots/{shoot}/generate-share-link', [ShootMediaController::class, 'generateShareLink'])->middleware('role:editor');
    Route::get('/shoots/{shoot}/share-links', [ShootMediaController::class, 'listShareLinks']);
    Route::post('/shoots/{shoot}/share-links/{linkId}/revoke', [ShootMediaController::class, 'revokeShareLink']);
    Route::post('/shoots/{shoot}/archive', [ShootMediaController::class, 'archiveShoot'])->middleware('role:admin,superadmin,editing_manager');
    Route::post('/shoots/{shoot}/files/{file}/move-to-completed', [ShootMediaController::class, 'moveFileToCompleted']);
    Route::post('/shoots/{shoot}/files/{file}/verify', [ShootMediaController::class, 'verifyFile']);
    Route::post('/shoots/{shoot}/files/{file}/extra', [ShootMediaController::class, 'toggleFileExtra']);
    // Retry-scan control: re-enqueue ScanShootFileJob for a file whose scan failed (Req 15.8).
    Route::post('/shoots/{shoot}/files/{file}/rescan', [App\Http\Controllers\API\FileScanController::class, 'rescan'])
        ->middleware('role:admin,superadmin,editing_manager');
    Route::get('/shoots/{shoot}/files/{file}/scan-failed-original', [App\Http\Controllers\API\FileScanController::class, 'downloadFailedOriginal'])
        ->middleware('role:superadmin');
    Route::post('/shoots/{shoot}/files/{file}/rebuild-preview', [App\Http\Controllers\API\FileScanController::class, 'rebuildPreview'])
        ->middleware('role:superadmin');
    Route::post('/shoots/{shoot}/generate-description', [ShootPublicAssetsController::class, 'generatePropertyDescription']);
    Route::get('/shoots/{shoot}/tour-analytics', [TourAnalyticsController::class, 'summary']);
    Route::patch('/shoots/{shoot}/files/reclassify', [ShootMediaController::class, 'reclassifyFiles']);
    Route::patch('/shoots/{shoot}/files/reorder', [ShootMediaController::class, 'reorderFiles']);
    Route::patch('/shoots/{shoot}/files/toggle-hidden', [ShootMediaController::class, 'toggleFileHidden']);
    // Bracket size is per shoot-service execution row, so it is addressed by that row.
    Route::patch(
        '/shoots/{shoot}/service-items/{shootService}/bracket-mode',
        [ShootMediaController::class, 'updateServiceBracketMode']
    );
    Route::get('/shoots/{shoot}/workflow-status', [ShootWorkflowController::class, 'getWorkflowStatus']);
    Route::prefix('/shoots/{shoot}/media')->group(function () {
        Route::post('{file}/favorite', [ShootMediaController::class, 'favoriteMedia']);
        Route::post('{file}/cover', [ShootMediaController::class, 'setCoverMedia']);
        Route::post('{file}/flag', [ShootMediaController::class, 'flagMedia']);
        Route::post('{file}/comment', [ShootMediaController::class, 'commentMedia']);
        Route::delete('{file}', [ShootMediaController::class, 'deleteMedia']);
        Route::get('{file}/download', [ShootMediaController::class, 'downloadMedia']);
        Route::post('bulk-download', [ShootMediaController::class, 'bulkDownloadMedia']);
        Route::post('bulk-delete', [ShootMediaController::class, 'bulkDeleteMedia']);
        Route::post('reorder', [ShootMediaController::class, 'reorderMedia']);
    });

    // Enhanced file upload endpoints
    Route::post('/shoots/{shoot}/upload-from-pc', [App\Http\Controllers\FileUploadController::class, 'uploadFromPC']);
    Route::post('/shoots/{shoot}/copy-from-dropbox', [App\Http\Controllers\FileUploadController::class, 'copyFromDropbox']);
    Route::get('/dropbox/browse', [App\Http\Controllers\FileUploadController::class, 'listDropboxFiles']);

    Route::get('/upload-sources', [UploadSourceController::class, 'index']);
    Route::post('/upload-sources/{provider}/connect', [UploadSourceController::class, 'connect'])
        ->whereIn('provider', \App\Services\UploadSourceService::PROVIDERS);
    Route::delete('/upload-sources/{provider}', [UploadSourceController::class, 'disconnect'])
        ->whereIn('provider', \App\Services\UploadSourceService::PROVIDERS);
    Route::get('/upload-sources/{provider}/items', [UploadSourceController::class, 'items'])
        ->whereIn('provider', \App\Services\UploadSourceService::PROVIDERS);

    // Finalize a shoot (admin toggle triggers this)
    Route::post('/shoots/{shoot}/finalize', [ShootPaymentsController::class, 'finalize']);
    // Live progress of the queued finalize pipeline (polled by the finalize toast)
    Route::get('/shoots/{shoot}/finalize-progress', [ShootPaymentsController::class, 'finalizeProgress']);

    // Shoot approval workflow endpoints
    Route::post('/shoots/{shoot}/mark-issues-resolved', [ShootIssuesController::class, 'markIssuesResolved']);
    Route::get('/shoots/{shoot}/issues', [ShootIssuesController::class, 'getIssues']);
    Route::post('/shoots/{shoot}/issues', [ShootIssuesController::class, 'createIssue']);
    Route::patch('/shoots/{shoot}/issues/{issue}', [ShootIssuesController::class, 'updateIssue']);
    Route::post('/shoots/{shoot}/issues/{issue}/assign', [ShootIssuesController::class, 'assignIssue']);

    // Client requests for admin dashboard
    Route::get('/client-requests', [ShootIssuesController::class, 'getClientRequests'])->middleware('role:admin,superadmin,editing_manager,editor,photographer,client');

    // Media uploads
    Route::post('/uploads/image', [MediaUploadController::class, 'uploadImage']);

    // Shoot messaging + reschedule requests
    Route::prefix('/shoots/{shoot}')->group(function () {
        Route::get('/messages', [ShootMessageController::class, 'index']);
        Route::post('/messages', [ShootMessageController::class, 'store']);
        Route::post('/reschedule', [ShootRescheduleRequestController::class, 'store']);
        Route::get('/reschedule-requests', [ShootRescheduleRequestController::class, 'index']);

        // Slideshow endpoints
        Route::get('/slideshows', [App\Http\Controllers\API\SlideshowController::class, 'index']);
        Route::post('/slideshows', [App\Http\Controllers\API\SlideshowController::class, 'store']);
        Route::patch('/slideshows/{slideshowId}', [App\Http\Controllers\API\SlideshowController::class, 'update']);
        Route::delete('/slideshows/{slideshowId}', [App\Http\Controllers\API\SlideshowController::class, 'destroy']);
        Route::get('/slideshows/{slideshowId}/download', [App\Http\Controllers\API\SlideshowController::class, 'download']);
    });

    Route::post('/shoots/messages/{message}/read', [ShootMessageController::class, 'markAsRead']);

    Route::prefix('editing-requests')->group(function () {
        Route::get('/', [EditingRequestController::class, 'index']);
        Route::post('/', [EditingRequestController::class, 'store']);
        Route::get('/{id}', [EditingRequestController::class, 'show']);
        Route::patch('/{id}', [EditingRequestController::class, 'update']);
        Route::delete('/{id}', [EditingRequestController::class, 'destroy']);
    });

    Route::prefix('editors')->group(function () {
        Route::get('/{editor}/rates', [EditorRatesController::class, 'show']);
        Route::put('/{editor}/rates', [EditorRatesController::class, 'update']);
    });

    // Robbie Chat endpoints
    // Note: OPTIONS requests are handled by HandleCors middleware automatically
    Route::prefix('ai')->group(function () {
        // Actual AI chat endpoints with role middleware
        Route::middleware('role:client,admin,superadmin,editing_manager')->group(function () {
            Route::post('/chat', [AiChatController::class, 'chat']);
            Route::post('/shoot-operator/action', [AiChatController::class, 'shootOperatorAction']);
            Route::get('/sessions', [AiChatController::class, 'sessions']);
            Route::get('/sessions/{session}', [AiChatController::class, 'sessionMessages']);
            Route::delete('/sessions/{session}', [AiChatController::class, 'deleteSession']);
            Route::post('/sessions/{session}/archive', [AiChatController::class, 'archiveSession']);
        });
    });

    // Autoenhance AI Photo Editing endpoints (Admin/Super Admin only)
    Route::prefix('autoenhance')->middleware('role:admin,superadmin,editing_manager,editor')->group(function () {
        Route::get('/connection-status', [AutoenhanceController::class, 'connectionStatus']);
        Route::get('/editing-types', [AutoenhanceController::class, 'getEditingTypes']);
        Route::post('/edit', [AutoenhanceController::class, 'submitEditing']);
        Route::post('/bracket-edit', [AutoenhanceController::class, 'submitBracketEditing']);
        Route::post('/quick-edit', [AutoenhanceController::class, 'quickEdit']);
        Route::post('/quick-edit/stage', [AutoenhanceController::class, 'stageQuickEdit']);
        Route::post('/jobs/poll', [AutoenhanceController::class, 'pollProcessingJobs']);
        Route::get('/jobs', [AutoenhanceController::class, 'listJobs']);
        Route::get('/jobs/{jobId}', [AutoenhanceController::class, 'getJobStatus']);
        Route::post('/jobs/{jobId}/cancel', [AutoenhanceController::class, 'cancelJob']);
        Route::post('/jobs/{jobId}/retry', [AutoenhanceController::class, 'retryJob']);
    });

    Route::match(['get', 'post'], 'webhooks/autoenhance', [AutoenhanceController::class, 'handleWebhook'])
        ->withoutMiddleware('auth:sanctum')
        ->name('webhooks.autoenhance');

    // fal.ai AI Listing Video endpoints
    Route::prefix('listing-videos')->middleware('role:admin,superadmin,editing_manager,editor')->group(function () {
        Route::post('/generate', [ListingVideoController::class, 'generate']);
        Route::get('/jobs', [ListingVideoController::class, 'index']);
        Route::get('/jobs/{job}', [ListingVideoController::class, 'show']);
        Route::post('/jobs/{job}/cancel', [ListingVideoController::class, 'cancel']);
    });

    // fal.ai AI Reel Generator endpoints
    Route::prefix('reels')->middleware('role:admin,superadmin,editing_manager,editor')->group(function () {
        Route::post('/generate', [ReelController::class, 'generate']);
        Route::get('/jobs', [ReelController::class, 'index']);
        Route::get('/jobs/{job}', [ReelController::class, 'show']);
        Route::post('/jobs/{job}/cancel', [ReelController::class, 'cancel']);
    });

    // AI Editing Studio endpoints. Endpoint-specific behavior is implemented by
    // the focused controllers while this group preserves one auth/role boundary.
    // The client rollout gate preserves drafts and grants while access is paused.
    // The existing Studio administration endpoints retain their original role boundary.
    Route::prefix('studio/workspaces')->middleware(['role:admin,superadmin,editing_manager,editor,client', \App\Http\Middleware\EnsureStudioClientAccess::class])->group(function () {
        $controller = \App\Http\Controllers\API\StudioWorkspaceController::class;
        $sources = \App\Http\Controllers\API\StudioWorkspaceSourceController::class;
        Route::get('/sources/shoots', [$sources, 'searchShoots']);
        Route::get('/sources/shoots/{shoot}/media', [$sources, 'shootMedia']);
        Route::post('/sources/uploads', [$sources, 'upload']);
        Route::get('/sources/uploads/preview', [$sources, 'uploadPreview']);
        Route::post('/sources/resolve', [$sources, 'resolve']);
        Route::get('/', [$controller, 'index']);
        Route::post('/', [$controller, 'store']);
        Route::get('/{workspace}', [$controller, 'show']);
        Route::get('/{workspace}/outputs/{output}/download', [$controller, 'download']);
        Route::patch('/{workspace}', [$controller, 'update']);
        Route::post('/{workspace}/prepare', [$controller, 'prepare']);
        Route::post('/{workspace}/generate', [$controller, 'generate']);
        Route::post('/{workspace}/revisions', [$controller, 'revisions']);
        Route::post('/{workspace}/segments', [$controller, 'segments']);
        Route::post('/{workspace}/cancel', [$controller, 'cancel']);
    });

    Route::prefix('studio')
        ->middleware('role:admin,superadmin,editing_manager,editor')
        ->name('studio.')
        ->group(function () {
            Route::prefix('metrics')->name('metrics.')->group(function () {
                Route::get('/hero', [StudioMetricsController::class, 'hero'])->name('hero');
                Route::get('/recent-projects', [StudioMetricsController::class, 'recentProjects'])->name('recent-projects');
                Route::get('/active-queue', [StudioMetricsController::class, 'activeQueue'])->name('active-queue');
                Route::get('/summary', [StudioMetricsController::class, 'summary'])->name('summary');
            });

            Route::get('/search', [StudioSearchController::class, 'index'])->name('search');
            Route::get('/queue', [StudioQueueController::class, 'index'])->name('queue.index');
            Route::get('/queue/{id}', [StudioQueueController::class, 'show'])->name('queue.show');

            Route::get('/projects', [StudioProjectController::class, 'index'])->name('projects.index');
            Route::post('/projects', [StudioProjectController::class, 'store'])->name('projects.store');
            Route::get('/projects/{project}', [StudioProjectController::class, 'show'])->name('projects.show');

            Route::get('/shoots/search', [StudioSourceController::class, 'searchShoots'])->name('shoots.search');
            Route::get('/shoots/{shoot}/media', [StudioSourceController::class, 'shootMedia'])->name('shoots.media');
            Route::post('/uploads', [StudioSourceController::class, 'upload'])->name('uploads.store');

            Route::get('/templates', [StudioTemplateController::class, 'index'])->name('templates.index');
            Route::post('/templates', [StudioTemplateController::class, 'store'])->name('templates.store');
            Route::put('/templates/{template}', [StudioTemplateController::class, 'update'])->name('templates.update');
            Route::delete('/templates/{template}', [StudioTemplateController::class, 'destroy'])->name('templates.destroy');

            Route::get('/brand', [StudioBrandController::class, 'show'])->name('brand.show');
            Route::put('/brand', [StudioBrandController::class, 'update'])->name('brand.update');
            Route::post('/deep-links/resolve', [StudioDeepLinkController::class, 'resolve'])->name('deep-links.resolve');
        });

    // Higgsfield AI Video Generation endpoints
    Route::prefix('higgsfield')->group(function () {
        // Presets - read for all authenticated users
        Route::get('/presets', [HiggsFieldController::class, 'getPresets']);

        // Presets - admin CRUD
        Route::middleware('role:admin,superadmin')->group(function () {
            Route::post('/presets', [HiggsFieldController::class, 'createPreset']);
            Route::put('/presets/{id}', [HiggsFieldController::class, 'updatePreset']);
            Route::delete('/presets/{id}', [HiggsFieldController::class, 'deletePreset']);
        });

        // Video generation - editing roles
        Route::middleware('role:admin,superadmin,editing_manager')->group(function () {
            Route::post('/generate', [HiggsFieldController::class, 'submitVideoGeneration']);
            Route::get('/jobs', [HiggsFieldController::class, 'listJobs']);
            Route::get('/jobs/{jobId}', [HiggsFieldController::class, 'getJobStatus']);
            Route::post('/jobs/{jobId}/select-variants', [HiggsFieldController::class, 'selectVariants']);
            Route::post('/jobs/{jobId}/regenerate-variants', [HiggsFieldController::class, 'regenerateVariants']);
            Route::post('/jobs/{jobId}/cancel', [HiggsFieldController::class, 'cancelJob']);
        });
    });

    // Integration endpoints
    Route::prefix('integrations')->group(function () {
        // Property lookup (available to all authenticated users)
        Route::post('/property/lookup', [IntegrationController::class, 'lookupProperty']);

        // Shoot-specific integration actions
        Route::prefix('shoots/{shoot}')->group(function () {
            Route::post('/property/refresh', [IntegrationController::class, 'refreshPropertyDetails']);
            Route::post('/iguide/sync', [IntegrationController::class, 'syncIguide'])
                ->middleware('role:admin,superadmin,editing_manager');
            Route::post('/iguide/offline-package', [IguideOfflinePackageController::class, 'store'])
                ->middleware('role:admin,superadmin,editing_manager');
            Route::post('/iguide/offline-package/view-link', IguideOfflineViewerLinkController::class)
                ->middleware('role:admin,superadmin,editing_manager,client');
            Route::middleware('role:admin,superadmin,editing_manager')
                ->prefix('/iguide/offline-package/uploads')
                ->group(function () {
                    Route::post('/', [IguideOfflineChunkUploadController::class, 'store']);
                    Route::get('/{upload}', [IguideOfflineChunkUploadController::class, 'show'])
                        ->whereUuid('upload');
                    Route::put('/{upload}/chunks/{index}', [IguideOfflineChunkUploadController::class, 'storeChunk'])
                        ->whereUuid('upload')
                        ->whereNumber('index');
                    Route::post('/{upload}/complete', [IguideOfflineChunkUploadController::class, 'complete'])
                        ->whereUuid('upload');
                    Route::delete('/{upload}', [IguideOfflineChunkUploadController::class, 'destroy'])
                        ->whereUuid('upload');
                });
            Route::post('/cubicasa/sync', [IntegrationController::class, 'syncCubicasa']);
            Route::post('/cubicasa/order', [IntegrationController::class, 'createCubicasa']);
            Route::post('/cubicasa/identifiers', [IntegrationController::class, 'saveCubicasaIdentifiers']);
            Route::post('/bright-mls/publish', [IntegrationController::class, 'publishToBrightMls']);
            Route::post('/mmm/punchout', [IntegrationController::class, 'mmmPunchout']);
            Route::get('/mmm/sessions', [IntegrationController::class, 'mmmSessions']);
        });

        // MLS Publishing Queue & redirect
        Route::middleware('role:admin,superadmin,editing_manager,client')->group(function () {
            Route::get('/mls-queue', [IntegrationController::class, 'getMlsQueue']);
            Route::get('/bright-mls/redirect/{manifestId}', [IntegrationController::class, 'getBrightMlsRedirectUrl']);
        });

        // Test connections (admin only)
        Route::middleware('role:admin,superadmin,editing_manager')->post('/test-connection', [IntegrationController::class, 'testConnection']);

        // Dropbox status
        Route::get('/dropbox/status', [IntegrationController::class, 'getDropboxStatus']);
    });

    // Settings endpoints (admin only)
    Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->prefix('admin/settings')->group(function () {
        Route::get('/{key}', [App\Http\Controllers\API\SettingsController::class, 'get']);
        Route::post('/', [App\Http\Controllers\API\SettingsController::class, 'store']);
    });

    // Watermark settings (superadmin only)
    Route::middleware(['auth:sanctum', 'role:superadmin'])->prefix('admin/watermark-settings')->group(function () {
        Route::get('/', [App\Http\Controllers\API\WatermarkSettingsController::class, 'index']);
        Route::put('/', [App\Http\Controllers\API\WatermarkSettingsController::class, 'update']);
        Route::post('/upload-logo', [App\Http\Controllers\API\WatermarkSettingsController::class, 'uploadLogo']);
        Route::get('/presets', [App\Http\Controllers\API\WatermarkSettingsController::class, 'presets']);
        Route::post('/regenerate', [App\Http\Controllers\API\WatermarkSettingsController::class, 'regenerate']);
        Route::get('/regeneration-progress/{regenerationId}', [App\Http\Controllers\API\WatermarkSettingsController::class, 'regenerationProgress']);
    });

    // Robbie health: is the language model actually being used, or is every reply
    // coming from the rule-based fallback? The fallback is silent by design, so
    // this is the only way to tell without reading logs.
    Route::middleware(['auth:sanctum', 'role:admin,superadmin'])
        ->get('admin/robbie-health', [App\Http\Controllers\API\RobbieHealthController::class, 'show']);

    // Robbie settings (superadmin only)
    Route::middleware(['auth:sanctum', 'role:superadmin'])->prefix('admin/robbie-settings')->group(function () {
        Route::get('/', [App\Http\Controllers\API\RobbieSettingsController::class, 'index']);
        Route::post('/', [App\Http\Controllers\API\RobbieSettingsController::class, 'store']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->patch(
    '/shoots/reschedule-requests/{rescheduleRequest}',
    [ShootRescheduleRequestController::class, 'updateStatus']
);

// Sales reps chase their own clients' payments, so they get the same manual
// reminder action as admins and editing managers (meeting [00:15:34]).
Route::middleware(['auth:sanctum', 'role:salesRep'])->prefix('salesrep')->group(function () {
    Route::post('invoices/{invoice}/send-reminder', [App\Http\Controllers\Admin\InvoiceReminderController::class, 'send']);
});

Route::middleware('auth:sanctum')->prefix('reports/invoices')->group(function () {
    Route::get('summary', [InvoiceReportController::class, 'summary']);
    Route::get('past-due', [InvoiceReportController::class, 'pastDue']);
});

// Sales rep reports
Route::middleware(['auth:sanctum', 'role:salesRep'])->prefix('reports/sales')->group(function () {
    Route::get('summary', [App\Http\Controllers\SalesReportController::class, 'mySummary']);
    Route::get('inactive-clients', [App\Http\Controllers\SalesReportController::class, 'myInactiveClients']);
    Route::get('weekly', [App\Http\Controllers\SalesReportController::class, 'myWeeklyReport']);
});

// Photographer invoice management
Route::middleware(['auth:sanctum', 'role:photographer'])->prefix('photographer/invoices')->group(function () {
    Route::get('/', [App\Http\Controllers\PhotographerInvoiceController::class, 'index']);
    Route::get('{invoice}', [App\Http\Controllers\PhotographerInvoiceController::class, 'show']);
    Route::post('{invoice}/expenses', [App\Http\Controllers\PhotographerInvoiceController::class, 'addExpense']);
    Route::delete('{invoice}/expenses/{item}', [App\Http\Controllers\PhotographerInvoiceController::class, 'removeExpense']);
    Route::post('{invoice}/charges', [App\Http\Controllers\PhotographerInvoiceController::class, 'addCharge']);
    Route::delete('{invoice}/charges/{item}', [App\Http\Controllers\PhotographerInvoiceController::class, 'removeCharge']);
    Route::patch('{invoice}/items/{item}', [App\Http\Controllers\PhotographerInvoiceController::class, 'updateItem']);
    Route::post('{invoice}/reject', [App\Http\Controllers\PhotographerInvoiceController::class, 'reject']);
    Route::post('{invoice}/submit-for-approval', [App\Http\Controllers\PhotographerInvoiceController::class, 'submitForApproval']);
});

// Sales rep invoice management
Route::middleware(['auth:sanctum', 'role:salesRep'])->prefix('salesrep/invoices')->group(function () {
    Route::get('/', [App\Http\Controllers\SalesRepInvoiceController::class, 'index']);
    Route::get('{invoice}', [App\Http\Controllers\SalesRepInvoiceController::class, 'show']);
    Route::post('{invoice}/expenses', [App\Http\Controllers\SalesRepInvoiceController::class, 'addExpense']);
    Route::delete('{invoice}/expenses/{item}', [App\Http\Controllers\SalesRepInvoiceController::class, 'removeExpense']);
    Route::post('{invoice}/reject', [App\Http\Controllers\SalesRepInvoiceController::class, 'reject']);
    Route::post('{invoice}/submit-for-approval', [App\Http\Controllers\SalesRepInvoiceController::class, 'submitForApproval']);
});

Route::middleware(['auth:sanctum', 'role:editor'])->prefix('editor')->group(function () {
    Route::get('earnings', [App\Http\Controllers\EditorPayoutController::class, 'earnings']);
    Route::get('reports', [App\Http\Controllers\EditorPayoutController::class, 'report']);
    Route::post('reports/send', [App\Http\Controllers\EditorPayoutController::class, 'sendReport']);
});

Route::get('/services', [ServiceController::class, 'index']);

Route::get('/services/{id}', [ServiceController::class, 'show']);

Route::get('/services/{id}/calculate-price', [ServiceController::class, 'calculatePrice']);

Route::get('/categories', [CategoryController::class, 'index']); // Public

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->group(function () {
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
});

Route::post('coupons/validate', [CouponController::class, 'validateCoupon']);

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->prefix('coupons')->group(function () {
    Route::get('/', [CouponController::class, 'index']);
    Route::post('/', [CouponController::class, 'store']);
    Route::patch('{coupon}', [CouponController::class, 'update']);
    Route::delete('{coupon}', [CouponController::class, 'destroy']);
    Route::post('{coupon}/toggle', [CouponController::class, 'toggleStatus']);
});

// CubiCasa scanning routes - accessible to photographers and admins
Route::middleware(['auth:sanctum', 'role:photographer,admin,superadmin,editing_manager'])->prefix('cubicasa')->group(function () {
    Route::post('/orders', [CubiCasaController::class, 'createOrder']);
    Route::get('/orders', [CubiCasaController::class, 'listOrders']);
    Route::get('/orders/{id}', [CubiCasaController::class, 'getOrder']);
    Route::post('/orders/{id}/photos', [CubiCasaController::class, 'uploadPhotos']);
    Route::get('/orders/{id}/status', [CubiCasaController::class, 'getOrderStatus']);
    Route::post('/orders/{id}/link-shoot', [CubiCasaController::class, 'linkToShoot']);
});

Route::prefix('photographer/availability')->group(function () {
    // Find all photographers available for given date & time
    Route::post('/available-photographers', [PhotographerAvailabilityController::class, 'availablePhotographers']);

    // Get comprehensive photographer info for booking (distance, availability, bookings)
    Route::post('/for-booking', [PhotographerAvailabilityController::class, 'getPhotographersForBooking']);
});

Route::middleware('auth:sanctum')->prefix('photographer/availability')->group(function () {
    Route::get('/{photographerId}', [PhotographerAvailabilityController::class, 'index']);
    Route::post('/', [PhotographerAvailabilityController::class, 'store']);
    Route::post('/bulk', [PhotographerAvailabilityController::class, 'bulkStore']);
    Route::post('/bulk-index', [PhotographerAvailabilityController::class, 'bulkIndex']);
    Route::post('/booked-slots', [PhotographerAvailabilityController::class, 'getBookedSlotsWithDetails']);
    Route::put('/{id}', [PhotographerAvailabilityController::class, 'update']);
    Route::delete('/{id}', [PhotographerAvailabilityController::class, 'destroy']);
    Route::delete('/clear/{photographerId}', [PhotographerAvailabilityController::class, 'clearAll']);
    Route::post('/check', [PhotographerAvailabilityController::class, 'checkAvailability']);
});

Route::middleware(['auth:sanctum'])->prefix('messaging')->group(function () {
    // Email (available to all authenticated users; controller enforces sender policy)
    Route::get('/email/recipients', [EmailMessagingController::class, 'recipients']);
    Route::get('/email/messages', [EmailMessagingController::class, 'messages']);
    Route::get('/email/messages/{message}', [EmailMessagingController::class, 'show']);
    Route::get('/email/threads', [EmailMessagingController::class, 'threads']);
    Route::post('/email/threads/{thread}/mark-read', [EmailMessagingController::class, 'markThreadRead']);
    Route::post('/email/compose', [EmailMessagingController::class, 'compose']);
    Route::post('/email/schedule', [EmailMessagingController::class, 'schedule']);
    Route::post('/email/messages/{message}/retry', [EmailMessagingController::class, 'retry']);
    Route::post('/email/messages/{message}/cancel', [EmailMessagingController::class, 'cancel']);

    Route::middleware('role:superadmin,admin')->group(function () {
        Route::get('/overview', MessagingOverviewController::class);
        Route::get('/email/ops-summary', EmailOpsSummaryController::class);
        Route::get('/email/recovery/client-confirmations', [ClientConfirmationRecoveryController::class, 'index']);
        Route::post('/email/recovery/client-confirmations/replay', [ClientConfirmationRecoveryController::class, 'replay']);

        // Templates
        Route::get('/templates', [MessageTemplateController::class, 'index']);
        Route::get('/templates/{template}', [MessageTemplateController::class, 'show']);
        Route::post('/templates', [MessageTemplateController::class, 'store']);
        Route::put('/templates/{template}', [MessageTemplateController::class, 'update']);
        Route::delete('/templates/{template}', [MessageTemplateController::class, 'destroy']);
        Route::post('/templates/{template}/duplicate', [MessageTemplateController::class, 'duplicate']);
        Route::post('/templates/{template}/test-send', [MessageTemplateController::class, 'testSend']);
        Route::post('/templates/{template}/preview', [MessageTemplateController::class, 'preview']);

        // Manual shoot notifications (Req 12.1, 12.5, 12.6, 12.7) — wraps ManualNotificationService;
        // shares this group's role:superadmin,admin restriction with the template routes above.
        Route::post('/notifications/manual-send', [MessageTemplateController::class, 'manualSend']);
        Route::post('/notifications/manual-preview', [MessageTemplateController::class, 'manualPreview']);

        // Automations
        Route::get('/automations', [AutomationController::class, 'index']);
        Route::post('/automations/validate', [AutomationController::class, 'validateWorkflow']);
        Route::get('/automations/{automation}', [AutomationController::class, 'show']);
        Route::post('/automations', [AutomationController::class, 'store']);
        Route::put('/automations/{automation}', [AutomationController::class, 'update']);
        Route::delete('/automations/{automation}', [AutomationController::class, 'destroy']);
        Route::post('/automations/{automation}/toggle', [AutomationController::class, 'toggleActive']);
        Route::post('/automations/{automation}/test', [AutomationController::class, 'test']);
        Route::post('/automations/{automation}/simulate', [AutomationController::class, 'simulate']);
        Route::get('/automations/{automation}/runs', [AutomationController::class, 'runs']);
        Route::post('/automations/{automation}/run', [AutomationController::class, 'runNow']);

        // Settings - Email
        Route::get('/settings/email', [MessagingSettingsController::class, 'emailSettings']);
        Route::post('/settings/email', [MessagingSettingsController::class, 'saveEmailSettings']);
        Route::post('/settings/email/channels', [MessagingSettingsController::class, 'createEmailChannel']);
        Route::put('/settings/email/channels/{channel}', [MessagingSettingsController::class, 'updateEmailChannel']);
        Route::delete('/settings/email/channels/{channel}', [MessagingSettingsController::class, 'deleteEmailChannel']);
        Route::post('/settings/email/channels/{channel}/test', [MessagingSettingsController::class, 'testEmailChannel']);

        // Settings - SMS
        Route::get('/settings/sms', [MessagingSettingsController::class, 'smsSettings']);
        Route::post('/settings/sms', [MessagingSettingsController::class, 'saveSmsSettings']);
        Route::post('/settings/sms/test-connection', [MessagingSettingsController::class, 'testSmsConnection']);
        Route::post('/settings/sms/test-send', [MessagingSettingsController::class, 'testSmsSend']);
        Route::delete('/settings/sms/numbers/{smsNumber}', [MessagingSettingsController::class, 'deleteSmsNumber']);
    });

    Route::middleware('role:superadmin,admin,editing_manager,sales_rep,photographer')->group(function () {
        Route::get('/sms/threads', [SmsMessagingController::class, 'threads']);
        Route::get('/sms/threads/{thread}', [SmsMessagingController::class, 'showThread']);
        Route::post('/sms/threads/{thread}/messages', [SmsMessagingController::class, 'sendToThread']);
        Route::post('/sms/threads/{thread}/resume-ai', [SmsMessagingController::class, 'resumeAi']);
        Route::post('/sms/send', [SmsMessagingController::class, 'send']);
        Route::post('/sms/threads/{thread}/mark-read', [SmsMessagingController::class, 'markRead']);
    });

    Route::middleware('role:superadmin,admin,editing_manager,sales_rep')->group(function () {
        Route::put('/contacts/{contact}', [SmsContactController::class, 'update']);
        Route::put('/contacts/{contact}/comment', [SmsContactController::class, 'updateComment']);
    });
});

// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/photographer/shoots', [PhotographerShootController::class, 'index']);
});

// Public read-only endpoints for client-facing pages
Route::prefix('public/shoots')->group(function () {
    // Address-based routes (address, city, state, zip query params)
    Route::get('branded', [ShootPublicAssetsController::class, 'publicBranded']);
    Route::get('mls', [ShootPublicAssetsController::class, 'publicMls']);
    Route::get('g-mls', [ShootPublicAssetsController::class, 'publicGenericMls']);

    Route::get('{shoot}/branded', [ShootPublicAssetsController::class, 'publicBranded']);
    Route::get('{shoot}/mls', [ShootPublicAssetsController::class, 'publicMls']);
    Route::get('{shoot}/g-mls', [ShootPublicAssetsController::class, 'publicGenericMls']);
});

// Public tour analytics tracking (unauthenticated, rate-limited)
Route::post('public/tour-events', [TourAnalyticsController::class, 'trackEvent'])->middleware('throttle:60,1');

// Onboarding telemetry — authenticated, role-gated to onboarded roles only
// (admin/superadmin are intentionally excluded; they have no onboarding flow).
Route::middleware(['auth:sanctum', 'role:client,photographer,salesRep,editing_manager,editor', 'throttle:120,1'])
    ->post('/onboarding/events', [OnboardingEventController::class, 'store']);

// Onboarding funnel summary — admin/superadmin only (query, no UI).
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])
    ->get('/onboarding/funnel', [OnboardingEventController::class, 'funnel']);

// Public client portfolio endpoints
Route::prefix('public')->group(function () {
    Route::get('/clients/{client}/profile', [ShootPublicAssetsController::class, 'publicClientProfile']);
    Route::post('/clients/{client}/contact', [App\Http\Controllers\API\ContactSubmissionController::class, 'storeByClient']);
    Route::post('/client/{username}/contact', [App\Http\Controllers\API\ContactSubmissionController::class, 'store']);
});

// Contact submissions management (requires auth)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/contact-submissions', [App\Http\Controllers\API\ContactSubmissionController::class, 'index']);
    Route::post('/contact-submissions/{submission}/read', [App\Http\Controllers\API\ContactSubmissionController::class, 'markAsRead']);
});

// Image download routes
Route::middleware(['auth:sanctum'])->prefix('images')->group(function () {
    Route::get('/{fileId}/download/original', [ImageDownloadController::class, 'downloadOriginal']);
    Route::get('/{fileId}/download/web', [ImageDownloadController::class, 'downloadWeb']);
    Route::post('/download/batch', [ImageDownloadController::class, 'downloadMultiple']);
});

// Image processing routes
Route::middleware(['auth:sanctum'])->prefix('images')->group(function () {
    Route::post('/{fileId}/process', [ImageProcessingController::class, 'processFile']);
    Route::post('/process/batch', [ImageProcessingController::class, 'processMultiple']);
    Route::get('/{fileId}/status', [ImageProcessingController::class, 'getStatus']);
    Route::post('/{fileId}/reprocess', [ImageProcessingController::class, 'reprocess']);
});

// Cakemail Email API routes
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->prefix('cakemail')->group(function () {
    Route::get('/test-connection', [App\Http\Controllers\API\CakemailController::class, 'testConnection']);
    Route::get('/senders', [App\Http\Controllers\API\CakemailController::class, 'getSenders']);
    Route::get('/lists', [App\Http\Controllers\API\CakemailController::class, 'getLists']);
    Route::get('/templates', [App\Http\Controllers\API\CakemailController::class, 'getTemplates']);
    Route::post('/templates', [App\Http\Controllers\API\CakemailController::class, 'createTemplate']);
    Route::post('/send-test', [App\Http\Controllers\API\CakemailController::class, 'sendTestEmail']);
    Route::post('/send-template', [App\Http\Controllers\API\CakemailController::class, 'sendTemplateEmail']);
    Route::post('/sync-contact', [App\Http\Controllers\API\CakemailController::class, 'syncContact']);
    Route::post('/sync-users', [App\Http\Controllers\API\CakemailController::class, 'syncUsers']);
    Route::get('/logs', [App\Http\Controllers\API\CakemailController::class, 'getLogs']);
    Route::post('/webhooks/register', [App\Http\Controllers\API\CakemailController::class, 'registerWebhook']);
    Route::post('/clear-cache', [App\Http\Controllers\API\CakemailController::class, 'clearCache']);
});

// RAW image preview routes
Route::middleware(['auth:sanctum'])->prefix('raw-preview')->group(function () {
    Route::post('/generate', [App\Http\Controllers\API\RawPreviewController::class, 'generate']);
    Route::post('/generate-async', [App\Http\Controllers\API\RawPreviewController::class, 'generateAsync']);
    Route::post('/batch', [App\Http\Controllers\API\RawPreviewController::class, 'generateBatch']);
    Route::get('/check', [App\Http\Controllers\API\RawPreviewController::class, 'check']);
    Route::get('/formats', [App\Http\Controllers\API\RawPreviewController::class, 'formats']);
    Route::delete('/delete', [App\Http\Controllers\API\RawPreviewController::class, 'delete']);
});

// Import routes (admin only)
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->prefix('import')->group(function () {
    Route::post('/accounts', [App\Http\Controllers\API\ImportController::class, 'importAccounts']);
    Route::post('/shoots', [App\Http\Controllers\API\ImportController::class, 'importShoots']);
    Route::get('/accounts/template', [App\Http\Controllers\API\ImportController::class, 'getAccountsTemplate']);
    Route::get('/shoots/template', [App\Http\Controllers\API\ImportController::class, 'getShootsTemplate']);
});
