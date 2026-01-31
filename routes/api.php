<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\API\ShootController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\PhotographerAvailabilityController;
use App\Http\Controllers\PhotographerShootController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\DropboxAuthController;
use App\Http\Controllers\InvoiceReportController;
use App\Http\Controllers\API\Messaging\AutomationController;
use App\Http\Controllers\API\Messaging\EmailMessagingController;
use App\Http\Controllers\API\Messaging\MessagingOverviewController;
use App\Http\Controllers\API\Messaging\MessageTemplateController;
use App\Http\Controllers\API\Messaging\MessagingSettingsController;
use App\Http\Controllers\API\Messaging\SmsContactController;
use App\Http\Controllers\API\Messaging\SmsMessagingController;
use App\Http\Controllers\API\CouponController;
use App\Http\Controllers\API\ShootMessageController;
use App\Http\Controllers\API\ShootRescheduleRequestController;
use App\Http\Controllers\API\MediaUploadController;
use App\Http\Controllers\API\EditingRequestController;
use App\Http\Controllers\API\AiChatController;
use App\Http\Controllers\API\CubiCasaController;
use App\Http\Controllers\API\ImageDownloadController;
use App\Http\Controllers\API\ImageProcessingController;
use App\Http\Controllers\API\FotelloController;
use App\Http\Controllers\API\EditorRatesController;
use App\Http\Controllers\Admin\AccountLinkController;
use App\Http\Controllers\API\IntegrationController;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/ping', function () {
    return response()->json([
        'status' => 'success',
        'timestamp' => now()->toIso8601String(),
        'message' => 'API is working V1'
    ]);
});

// Debug route to check PHP upload limits
Route::get('/php-limits', function () {
    return response()->json([
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_file_uploads' => ini_get('max_file_uploads'),
    ]);
});

// AI Chat health check (no auth required)
Route::get('/ai/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Robbie AI Chat',
        'timestamp' => now()->toIso8601String(),
        'routes_loaded' => true,
    ]);
});

// AI Chat test endpoint (with auth, tests role middleware)
Route::middleware('auth:sanctum')->get('/ai/test-auth', function (Request $request) {
    $user = $request->user();
    return response()->json([
        'status' => 'ok',
        'authenticated' => $user !== null,
        'user_id' => $user?->id,
        'user_role' => $user?->role,
        'allowed_roles' => ['client', 'admin', 'superadmin'],
        'has_access' => $user && in_array($user->role, ['client', 'admin', 'superadmin']),
    ]);
});

// AI Chat test endpoint (with auth, minimal logic)
Route::post('/ai/test', function (Request $request) {
    $user = $request->user();
    if (!$user) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    
    try {
        // Test if we can create a session
        $session = \App\Models\AiChatSession::create([
            'user_id' => $user->id,
            'title' => 'Test session',
        ]);
        
        return response()->json([
            'status' => 'success',
            'session_id' => $session->id,
            'message' => 'Basic functionality works',
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
})->middleware('auth:sanctum');

Route::prefix('dropbox')->name('dropbox.')->group(function () {
    // Config (public endpoint for frontend)
    Route::get('config', [DropboxAuthController::class, 'getConfig'])->name('config');
    
    // Auth
    Route::get('connect', [DropboxAuthController::class, 'connect'])->name('connect');
    Route::get('callback', [DropboxAuthController::class, 'callback'])->name('callback');
    Route::post('disconnect', [DropboxAuthController::class, 'disconnect'])->name('disconnect');

    // User Info
    Route::get('user', [DropboxAuthController::class, 'getUserAccount'])->name('user');

    // File Operations
    Route::get('files/list', [DropboxAuthController::class, 'listFiles'])->name('files.list');
    Route::post('files/upload', [DropboxAuthController::class, 'uploadFile'])->name('files.upload');
    Route::get('files/download', [DropboxAuthController::class, 'downloadFile'])->name('files.download');
    Route::post('files/delete', [DropboxAuthController::class, 'deleteFile'])->name('files.delete');

    // Webhook (can be in api.php if it's stateless)
    Route::match(['get', 'post'], 'webhook', [DropboxAuthController::class, 'webhook'])->name('webhook');
});

// Route::post('/shoots/{shoot}/create-payment-link', [PaymentController::class, 'createCheckoutLink']);

Route::post('webhooks/square', [PaymentController::class, 'handleWebhook'])
    ->middleware('square.webhook') // Verifies the request is genuinely from Square
    ->name('webhooks.square');

// MightyCall SMS Webhooks (no auth - webhook verification handled in controller)
Route::match(['get', 'post'], 'webhooks/mightycall', [App\Http\Controllers\API\Messaging\MightyCallWebhookController::class, 'handle'])
    ->name('webhooks.mightycall');

// Cakemail Email Webhooks (no auth - webhook verification handled in controller)
Route::match(['get', 'post'], 'webhooks/cakemail', [App\Http\Controllers\API\CakemailWebhookController::class, 'handle'])
    ->name('webhooks.cakemail');

// MMM Punchout return callback (public endpoint)
Route::post('integrations/mmm/return', [IntegrationController::class, 'mmmReturn'])
    ->name('integrations.mmm.return');

// Test endpoints (remove in production)
Route::get('test/dropbox-config', [App\Http\Controllers\TestDropboxController::class, 'debugConfig']);
Route::get('test/dropbox-curl', [App\Http\Controllers\TestDropboxController::class, 'testWithCurl']);
Route::get('test/dropbox-connection', [App\Http\Controllers\TestDropboxController::class, 'testConnection']);
Route::get('test/dropbox-folder', [App\Http\Controllers\TestDropboxController::class, 'testFolderCreation']);
Route::get('test/folder-structure', [App\Http\Controllers\TestDropboxController::class, 'testFolderStructure']);
Route::get('test/create-shoot', [App\Http\Controllers\TestDropboxController::class, 'createTestShoot']);
Route::post('test/create-shoot-api', [App\Http\Controllers\TestDropboxController::class, 'createTestShootViaAPI']);
Route::get('dropbox/setup-long-lived-token', [App\Http\Controllers\TestDropboxController::class, 'setupLongLivedToken']);

// Square API test endpoints
Route::get('test/square-connection', [App\Http\Controllers\TestSquareController::class, 'testConnection']);
Route::get('test/square-locations', [App\Http\Controllers\TestSquareController::class, 'listLocations']);

// Square configuration endpoint (for frontend) - requires authentication
Route::middleware('auth:sanctum')->get('square/config', [App\Http\Controllers\TestSquareController::class, 'getConfig'])
    ->name('api.square.config');

// Address lookup endpoints
Route::prefix('address')->group(function () {
    Route::get('search', [App\Http\Controllers\AddressLookupController::class, 'searchAddresses']);
    Route::get('details', [App\Http\Controllers\AddressLookupController::class, 'getAddressDetails']);
    Route::post('validate', [App\Http\Controllers\AddressLookupController::class, 'validateAddress']);
    Route::post('distance', [App\Http\Controllers\AddressLookupController::class, 'calculateDistance']);
    Route::get('service-area', [App\Http\Controllers\AddressLookupController::class, 'checkServiceArea']);
    Route::get('nearby-photographers', [App\Http\Controllers\AddressLookupController::class, 'getNearbyPhotographers']);
    
    // Address provider settings (admin only)
    Route::middleware('auth:sanctum')->prefix('provider')->group(function () {
        Route::get('/', [App\Http\Controllers\API\AddressProviderSettingsController::class, 'getProvider']);
        Route::put('/', [App\Http\Controllers\API\AddressProviderSettingsController::class, 'updateProvider']);
    });
});

// Mail test endpoints (remove in production)
Route::prefix('test/mail')->group(function () {
    Route::get('config', [App\Http\Controllers\TestMailController::class, 'getMailConfig']);
    Route::get('account-created', [App\Http\Controllers\TestMailController::class, 'testAccountCreated']);
    Route::get('shoot-scheduled', [App\Http\Controllers\TestMailController::class, 'testShootScheduled']);
    Route::get('shoot-ready', [App\Http\Controllers\TestMailController::class, 'testShootReady']);
    Route::get('payment-confirmation', [App\Http\Controllers\TestMailController::class, 'testPaymentConfirmation']);
    Route::get('all', [App\Http\Controllers\TestMailController::class, 'testAllEmails']);
});

// Group of routes that require user authentication (e.g., using Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    
    // Creates a checkout link for a specific photography shoot.
    // The {shoot} parameter is a route model binding.
    // e.g., POST /api/shoots/123/create-checkout-link
    Route::post('shoots/{shoot}/create-checkout-link', [PaymentController::class, 'createCheckoutLink'])
        ->name('api.shoots.payment.create-link');

    // Pay for multiple shoots
    Route::post('payments/multiple-shoots', [PaymentController::class, 'payMultipleShoots'])
        ->name('api.payments.multiple-shoots');

    // Process direct payment using Square Web Payments SDK token
    Route::post('payments/create', [PaymentController::class, 'createPayment'])
        ->name('api.payments.create');

    // Initiates a refund for a given payment.
    // The Square Payment ID should be sent in the request body.
    // e.g., POST /api/payments/refund
    Route::post('payments/refund', [PaymentController::class, 'refundPayment'])
        ->name('api.payments.refund');

});

Route::post('/register', [AuthController::class, 'register']);

Route::post('/login', [AuthController::class, 'login']);

// Password Reset Routes (public)
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [AuthController::class, 'resetPasswordWithToken']);

Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// Self profile update (authenticated user updates their own profile)
Route::middleware('auth:sanctum')->put('/profile', [AuthController::class, 'updateProfile']);

// Debug route to check current user role
Route::middleware('auth:sanctum')->get('/debug/user-role', function (Request $request) {
    $user = $request->user();
    return response()->json([
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => $user->role,
        'name' => $user->name,
        'can_access_users' => in_array($user->role, ['admin', 'superadmin']),
        'can_access_account_links' => in_array($user->role, ['admin', 'superadmin'])
    ]);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,salesRep'])->get('/admin/users', [UserController::class, 'index']);

Route::middleware(['auth:sanctum','role:admin,superadmin,editing_manager'])->patch('/admin/users/{id}/role', [UserController::class, 'updateRole']);
Route::middleware(['auth:sanctum','role:admin,superadmin,editing_manager'])->patch('/admin/users/{id}/password', [UserController::class, 'resetPassword']);
Route::middleware(['auth:sanctum','role:admin,superadmin,editing_manager'])->post('/admin/users/{id}/send-reset-link', [UserController::class, 'sendResetLink']);
Route::middleware(['auth:sanctum','role:admin,superadmin,editing_manager'])->put('/admin/users/{id}', [UserController::class, 'update']);
Route::middleware(['auth:sanctum','role:admin,superadmin,editing_manager'])->delete('/admin/users/{id}', [UserController::class, 'destroy']);
Route::middleware(['auth:sanctum'])->post('/admin/users', [UserController::class, 'store']);

// Account Linking Routes - Admin endpoints
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->group(function () {
    Route::get('/admin/account-links', [AccountLinkController::class, 'index']);
    Route::post('/admin/account-links', [AccountLinkController::class, 'store']);
    Route::post('/admin/account-links/batch', [AccountLinkController::class, 'batchStore']);
    Route::patch('/admin/account-links/{id}', [AccountLinkController::class, 'update']);
    Route::delete('/admin/account-links/{id}', [AccountLinkController::class, 'destroy']);
    Route::get('/admin/account-links/shared-data/{accountId}', [AccountLinkController::class, 'getSharedData']);
    Route::get('/admin/account-links/available-accounts', [AccountLinkController::class, 'getAvailableAccounts']);
});

// Account Linking Routes - User-facing endpoints (accessible to all authenticated users)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/account-links/has-linked', [AccountLinkController::class, 'hasLinkedAccounts']);
    Route::get('/account-links/my-linked-accounts', [AccountLinkController::class, 'getLinkedAccountsForUser']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->get('/admin/clients', [UserController::class, 'getClients']);

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager,client'])->get('/admin/photographers', [UserController::class, 'getPhotographers']);
// Public lightweight list for dropdowns
Route::get('/photographers', [UserController::class, 'simplePhotographers']);

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->group(function () {
    Route::post('/admin/services', [ServiceController::class, 'store']);

    Route::put('/admin/services/{id}', [ServiceController::class, 'update']);

    Route::delete('/admin/services/{id}', [ServiceController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->get(
    '/dashboard/overview',
    [DashboardController::class, 'overview']
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

Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->prefix('admin')->group(function () {
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{invoice}/download', [InvoiceController::class, 'download']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('invoices/generate', [InvoiceController::class, 'generate']);
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);
    
    // Invoice approval endpoints
    Route::get('invoices/pending-approval', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'pending']);
    Route::post('invoices/{invoice}/approve', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'approve']);
    Route::post('invoices/{invoice}/reject', [App\Http\Controllers\Admin\InvoiceApprovalController::class, 'reject']);
    
    // Sales report endpoints
    Route::get('sales-reports/{salesRepId}', [App\Http\Controllers\SalesReportController::class, 'salesRepReport']);
    Route::post('sales-reports/send-weekly', [App\Http\Controllers\SalesReportController::class, 'sendWeeklyReports']);
});

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
    Route::put('/users/{user}/branding', [App\Http\Controllers\API\UserBrandingController::class, 'update'])->middleware('role:admin,superadmin,editing_manager');
    
    // Shoot management
    Route::get('/shoots', [ShootController::class, 'index']);
    Route::post('/shoots', [ShootController::class, 'store']);
    // History routes must come before /shoots/{shoot} to avoid route conflict
    Route::get('/shoots/history', [ShootController::class, 'history']);
    Route::get('/shoots/history/export', [ShootController::class, 'exportHistory']);
    Route::get('/shoots/{shoot}', [ShootController::class, 'show']);
    Route::get('/shoots/{shoot}/invoice', [ShootController::class, 'getOrCreateInvoice']);
    // Minimal update endpoint for status/workflow updates
    Route::patch('/shoots/{shoot}', [ShootController::class, 'update']);
    // Mark shoot as paid (Admin and Super Admin)
    Route::post('/shoots/{shoot}/mark-paid', [ShootController::class, 'markAsPaid'])->middleware('role:admin,superadmin');
    // State transition endpoints
    Route::post('/shoots/{shoot}/schedule', [ShootController::class, 'schedule']);
    Route::post('/shoots/{shoot}/assign-editor', [ShootController::class, 'assignEditor'])
        ->middleware('role:admin,superadmin,editing_manager');
    Route::post('/shoots/{shoot}/start-editing', [ShootController::class, 'startEditing']);
    Route::post('/shoots/{shoot}/ready-for-review', [ShootController::class, 'readyForReview']);
    Route::post('/shoots/{shoot}/complete', [ShootController::class, 'complete']);
    Route::post('/shoots/{shoot}/put-on-hold', [ShootController::class, 'putOnHold']);
    Route::post('/shoots/{shoot}/approve', [ShootController::class, 'approve']);
    Route::post('/shoots/{shoot}/decline', [ShootController::class, 'decline']);
    
    // Cancellation request endpoints
    Route::post('/shoots/{shoot}/request-cancellation', [ShootController::class, 'requestCancellation']);
    Route::post('/shoots/{shoot}/approve-cancellation', [ShootController::class, 'approveCancellation']);
    Route::post('/shoots/{shoot}/reject-cancellation', [ShootController::class, 'rejectCancellation']);
    Route::get('/shoots/pending-cancellations', [ShootController::class, 'pendingCancellations']);
    // Direct cancel endpoint for admin use
    Route::post('/shoots/{shoot}/cancel', [ShootController::class, 'cancel'])->middleware('role:admin,superadmin');
    
    // Photographer availability
    Route::get('/photographers/{id}/availability', [ShootController::class, 'getPhotographerAvailability']);
    
    // Media albums
    Route::post('/shoots/{shoot}/albums', [ShootController::class, 'createAlbum']);
    Route::get('/shoots/{shoot}/albums', [ShootController::class, 'listAlbums']);
    Route::post('/shoots/{shoot}/media', [ShootController::class, 'uploadMedia']);
    
    // Notes
    Route::get('/shoots/{shoot}/notes', [ShootController::class, 'getNotes']);
    Route::post('/shoots/{shoot}/notes', [ShootController::class, 'storeNote']);
    Route::patch('/shoots/{shoot}/notes', [ShootController::class, 'updateNotesSimple']);
    
    // Activity Log
    Route::get('/shoots/{shoot}/activity-log', [ShootController::class, 'getActivityLog']);
    Route::delete('/shoots/{shoot}', [ShootController::class, 'destroy'])->middleware('role:admin,superadmin,editing_manager');
    
    // File workflow endpoints
    Route::post('/shoots/{shoot}/upload', [ShootController::class, 'uploadFiles']);
    Route::post('/shoots/{shoot}/upload-extra', [ShootController::class, 'uploadExtra']);
    Route::get('/shoots/{shoot}/files', [ShootController::class, 'getFiles']);
    Route::get('/shoots/{shoot}/files/{file}/preview', [ShootController::class, 'previewFile']);
    Route::get('/shoots/{shoot}/media', [ShootController::class, 'listMedia']);
    Route::get('/shoots/{shoot}/media/download-zip', [ShootController::class, 'downloadMediaZip']);
    Route::get('/shoots/{shoot}/editor-download-raw', [ShootController::class, 'editorDownloadRaw'])->middleware('role:editor');
    Route::post('/shoots/{shoot}/generate-share-link', [ShootController::class, 'generateShareLink'])->middleware('role:editor');
    Route::get('/shoots/{shoot}/share-links', [ShootController::class, 'listShareLinks']);
    Route::post('/shoots/{shoot}/share-links/{linkId}/revoke', [ShootController::class, 'revokeShareLink']);
    Route::post('/shoots/{shoot}/archive', [ShootController::class, 'archiveShoot'])->middleware('role:admin,superadmin,editing_manager');
    Route::post('/shoots/{shoot}/files/{file}/move-to-completed', [ShootController::class, 'moveFileToCompleted']);
    Route::post('/shoots/{shoot}/files/{file}/verify', [ShootController::class, 'verifyFile']);
    Route::post('/shoots/{shoot}/files/{file}/extra', [ShootController::class, 'toggleFileExtra']);
    Route::get('/shoots/{shoot}/workflow-status', [ShootController::class, 'getWorkflowStatus']);
    Route::prefix('/shoots/{shoot}/media')->group(function () {
        Route::post('{file}/favorite', [ShootController::class, 'favoriteMedia']);
        Route::post('{file}/cover', [ShootController::class, 'setCoverMedia']);
        Route::post('{file}/flag', [ShootController::class, 'flagMedia']);
        Route::post('{file}/comment', [ShootController::class, 'commentMedia']);
        Route::delete('{file}', [ShootController::class, 'deleteMedia']);
        Route::get('{file}/download', [ShootController::class, 'downloadMedia']);
        Route::post('bulk-download', [ShootController::class, 'bulkDownloadMedia']);
        Route::post('bulk-delete', [ShootController::class, 'bulkDeleteMedia']);
        Route::post('reorder', [ShootController::class, 'reorderMedia']);
    });
    
    // Enhanced file upload endpoints
    Route::post('/shoots/{shoot}/upload-from-pc', [App\Http\Controllers\FileUploadController::class, 'uploadFromPC']);
    Route::post('/shoots/{shoot}/copy-from-dropbox', [App\Http\Controllers\FileUploadController::class, 'copyFromDropbox']);
    Route::get('/dropbox/browse', [App\Http\Controllers\FileUploadController::class, 'listDropboxFiles']);

    // Finalize a shoot (admin toggle triggers this)
    Route::post('/shoots/{shoot}/finalize', [ShootController::class, 'finalize']);
    
    // Shoot approval workflow endpoints
    Route::post('/shoots/{shoot}/mark-issues-resolved', [ShootController::class, 'markIssuesResolved']);
    Route::get('/shoots/{shoot}/issues', [ShootController::class, 'getIssues']);
    Route::post('/shoots/{shoot}/issues', [ShootController::class, 'createIssue']);
    Route::patch('/shoots/{shoot}/issues/{issue}', [ShootController::class, 'updateIssue']);
    Route::post('/shoots/{shoot}/issues/{issue}/assign', [ShootController::class, 'assignIssue']);
    
    // Client requests for admin dashboard
    Route::get('/client-requests', [ShootController::class, 'getClientRequests'])->middleware('role:admin,superadmin,editing_manager');

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

    // Client-submitted shoot requests (status = requested) for admin dashboard
    Route::get('/client-requests', [ShootController::class, 'clientRequests']);

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
        // Test endpoint without role middleware to isolate the issue
        Route::post('/chat-test', function (Request $request) {
            $user = $request->user();
            return response()->json([
                'status' => 'ok',
                'authenticated' => $user !== null,
                'user_id' => $user?->id,
                'user_role' => $user?->role,
                'message' => 'This endpoint works without role middleware',
            ]);
        });
        
        // Actual AI chat endpoints with role middleware
        Route::middleware('role:client,admin,superadmin,editing_manager')->group(function () {
            Route::post('/chat', [AiChatController::class, 'chat']);
            Route::get('/sessions', [AiChatController::class, 'sessions']);
            Route::get('/sessions/{session}', [AiChatController::class, 'sessionMessages']);
            Route::delete('/sessions/{session}', [AiChatController::class, 'deleteSession']);
            Route::post('/sessions/{session}/archive', [AiChatController::class, 'archiveSession']);
        });
    });

    // Fotello AI Photo Editing endpoints (Admin/Super Admin only)
    Route::prefix('fotello')->middleware('role:admin,superadmin,editing_manager')->group(function () {
        Route::get('/editing-types', [FotelloController::class, 'getEditingTypes']);
        Route::post('/edit', [FotelloController::class, 'submitEditing']);
        Route::get('/jobs', [FotelloController::class, 'listJobs']);
        Route::get('/jobs/{jobId}', [FotelloController::class, 'getJobStatus']);
        Route::post('/jobs/{jobId}/cancel', [FotelloController::class, 'cancelJob']);
    });

    // Integration endpoints
    Route::prefix('integrations')->group(function () {
        // Property lookup (available to all authenticated users)
        Route::post('/property/lookup', [IntegrationController::class, 'lookupProperty']);
        
        // Shoot-specific integration actions
        Route::prefix('shoots/{shoot}')->group(function () {
            Route::post('/property/refresh', [IntegrationController::class, 'refreshPropertyDetails']);
            Route::post('/iguide/sync', [IntegrationController::class, 'syncIguide']);
            Route::post('/bright-mls/publish', [IntegrationController::class, 'publishToBrightMls']);
            Route::post('/mmm/punchout', [IntegrationController::class, 'mmmPunchout']);
        });

        // MLS Publishing Queue (admin only)
        Route::middleware('role:admin,superadmin,editing_manager')->group(function () {
            Route::get('/mls-queue', [IntegrationController::class, 'getMlsQueue']);
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
        Route::get('/debug-files', [App\Http\Controllers\API\WatermarkSettingsController::class, 'debugFiles']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->patch(
    '/shoots/reschedule-requests/{rescheduleRequest}',
    [ShootRescheduleRequestController::class, 'updateStatus']
);

Route::middleware('auth:sanctum')->prefix('reports/invoices')->group(function () {
    Route::get('summary', [InvoiceReportController::class, 'summary']);
    Route::get('past-due', [InvoiceReportController::class, 'pastDue']);
});

// Sales rep reports
Route::middleware(['auth:sanctum', 'role:salesRep'])->prefix('reports/sales')->group(function () {
    Route::get('weekly', [App\Http\Controllers\SalesReportController::class, 'myWeeklyReport']);
});

// Photographer invoice management
Route::middleware(['auth:sanctum', 'role:photographer'])->prefix('photographer/invoices')->group(function () {
    Route::get('/', [App\Http\Controllers\PhotographerInvoiceController::class, 'index']);
    Route::get('{invoice}', [App\Http\Controllers\PhotographerInvoiceController::class, 'show']);
    Route::post('{invoice}/expenses', [App\Http\Controllers\PhotographerInvoiceController::class, 'addExpense']);
    Route::delete('{invoice}/expenses/{item}', [App\Http\Controllers\PhotographerInvoiceController::class, 'removeExpense']);
    Route::post('{invoice}/reject', [App\Http\Controllers\PhotographerInvoiceController::class, 'reject']);
    Route::post('{invoice}/submit-for-approval', [App\Http\Controllers\PhotographerInvoiceController::class, 'submitForApproval']);
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

Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->prefix('coupons')->group(function () {
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
    // Get all availability for a photographer
    Route::get('/{photographerId}', [PhotographerAvailabilityController::class, 'index']);

    // Add single availability
    Route::post('/', [PhotographerAvailabilityController::class, 'store']);

    // Bulk add availability (weekly schedule)
    Route::post('/bulk', [PhotographerAvailabilityController::class, 'bulkStore']);

    // Bulk fetch availability for multiple photographers (optimized)
    Route::post('/bulk-index', [PhotographerAvailabilityController::class, 'bulkIndex']);

    // Get booked slots with shoot details for a photographer
    Route::post('/booked-slots', [PhotographerAvailabilityController::class, 'getBookedSlotsWithDetails']);

    // Update availability
    Route::put('/{id}', [PhotographerAvailabilityController::class, 'update']);

    // Delete single availability
    Route::delete('/{id}', [PhotographerAvailabilityController::class, 'destroy']);

    // Clear all availability for a photographer
    Route::delete('/clear/{photographerId}', [PhotographerAvailabilityController::class, 'clearAll']);

    // Check availability for a specific date (for one photographer)
    Route::post('/check', [PhotographerAvailabilityController::class, 'checkAvailability']);

    // Find all photographers available for given date & time
    Route::post('/available-photographers', [PhotographerAvailabilityController::class, 'availablePhotographers']);

    // Get comprehensive photographer info for booking (distance, availability, bookings)
    Route::post('/for-booking', [PhotographerAvailabilityController::class, 'getPhotographersForBooking']);
});

Route::middleware(['auth:sanctum'])->prefix('messaging')->group(function () {
    // Email (available to all authenticated users; controller enforces sender policy)
    Route::get('/email/messages', [EmailMessagingController::class, 'messages']);
    Route::get('/email/messages/{message}', [EmailMessagingController::class, 'show']);
    Route::get('/email/threads', [EmailMessagingController::class, 'threads']);
    Route::post('/email/compose', [EmailMessagingController::class, 'compose']);
    Route::post('/email/schedule', [EmailMessagingController::class, 'schedule']);
    Route::post('/email/messages/{message}/retry', [EmailMessagingController::class, 'retry']);
    Route::post('/email/messages/{message}/cancel', [EmailMessagingController::class, 'cancel']);

    Route::middleware('role:superadmin,admin,editing_manager,sales_rep')->group(function () {
        Route::get('/overview', MessagingOverviewController::class);

        // Templates
        Route::get('/templates', [MessageTemplateController::class, 'index']);
        Route::get('/templates/{template}', [MessageTemplateController::class, 'show']);
        Route::post('/templates', [MessageTemplateController::class, 'store']);
        Route::put('/templates/{template}', [MessageTemplateController::class, 'update']);
        Route::delete('/templates/{template}', [MessageTemplateController::class, 'destroy']);
        Route::post('/templates/{template}/duplicate', [MessageTemplateController::class, 'duplicate']);
        Route::post('/templates/{template}/test-send', [MessageTemplateController::class, 'testSend']);
        Route::post('/templates/{template}/preview', [MessageTemplateController::class, 'preview']);

        // Automations
        Route::get('/automations', [AutomationController::class, 'index']);
        Route::get('/automations/{automation}', [AutomationController::class, 'show']);
        Route::post('/automations', [AutomationController::class, 'store']);
        Route::put('/automations/{automation}', [AutomationController::class, 'update']);
        Route::delete('/automations/{automation}', [AutomationController::class, 'destroy']);
        Route::post('/automations/{automation}/toggle', [AutomationController::class, 'toggleActive']);
        Route::post('/automations/{automation}/test', [AutomationController::class, 'test']);

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
        Route::post('/settings/sms/sync', [MessagingSettingsController::class, 'syncSmsMessages']);
    });

    Route::middleware('role:superadmin,admin,editing_manager,sales_rep,photographer')->group(function () {
        Route::get('/sms/threads', [SmsMessagingController::class, 'threads']);
        Route::get('/sms/threads/{thread}', [SmsMessagingController::class, 'showThread']);
        Route::post('/sms/threads/{thread}/messages', [SmsMessagingController::class, 'sendToThread']);
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
    Route::get('{shoot}/branded', [ShootController::class, 'publicBranded']);
    Route::get('{shoot}/mls', [ShootController::class, 'publicMls']);
    Route::get('{shoot}/g-mls', [ShootController::class, 'publicGenericMls']);
});

// Client profile (requires authentication and authorization)
Route::middleware('auth:sanctum')->prefix('public')->group(function () {
    Route::get('/clients/{client}/profile', [ShootController::class, 'publicClientProfile']);
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
    Route::post('/generate', [App\Http\Controllers\Api\RawPreviewController::class, 'generate']);
    Route::post('/generate-async', [App\Http\Controllers\Api\RawPreviewController::class, 'generateAsync']);
    Route::post('/batch', [App\Http\Controllers\Api\RawPreviewController::class, 'generateBatch']);
    Route::get('/check', [App\Http\Controllers\Api\RawPreviewController::class, 'check']);
    Route::get('/formats', [App\Http\Controllers\Api\RawPreviewController::class, 'formats']);
    Route::delete('/delete', [App\Http\Controllers\Api\RawPreviewController::class, 'delete']);
});

// Import routes (admin only)
Route::middleware(['auth:sanctum', 'role:admin,superadmin,editing_manager'])->prefix('import')->group(function () {
    Route::post('/accounts', [App\Http\Controllers\API\ImportController::class, 'importAccounts']);
    Route::post('/shoots', [App\Http\Controllers\API\ImportController::class, 'importShoots']);
    Route::get('/accounts/template', [App\Http\Controllers\API\ImportController::class, 'getAccountsTemplate']);
    Route::get('/shoots/template', [App\Http\Controllers\API\ImportController::class, 'getShootsTemplate']);
});

