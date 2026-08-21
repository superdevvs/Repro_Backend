<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\FinalizeShootJob;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\Invoices\InvoiceAuthorizationService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Payments\PublicPaymentAccessTokenService;
use App\Services\Payments\StripePaymentMetadataService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\FinalizeProgressTracker;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootPaymentStatusSupport;
use App\Services\Shoots\ShootServiceItemSupport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShootPaymentsController extends Controller
{
    private const INTENT_METHODS = ['cash', 'check'];

    private const FINALIZE_ROLES = ['admin', 'superadmin', 'editing_manager'];

    public function __construct(
        protected InvoiceService $invoiceService,
        protected MailService $mailService,
        protected ShootActivityLogger $activityLogger,
        protected ShootPaymentStatusSupport $shootPaymentStatusSupport,
        protected StripePaymentMetadataService $stripePaymentMetadataService,
        protected ShootServiceItemSupport $serviceItemSupport,
        protected InvoiceAdjustmentService $invoiceAdjustments,
        protected InvoiceAuthorizationService $invoiceAuthorization,
        protected ShootAuthorizationSupport $authorizationSupport,
        protected FinalizeProgressTracker $finalizeProgress
    ) {}

    public function finalize(Request $request, $shootId)
    {
        $user = auth()->user();
        if (! $user || ! in_array($user->role, self::FINALIZE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'final_status' => 'nullable|string|in:admin_verified,completed',
            'shoot_service_id' => 'nullable|integer|exists:shoot_service,id',
            'allow_no_media_delivery' => 'nullable|boolean',
        ]);

        $shoot = Shoot::with(['files'])->findOrFail($shootId);
        $shootServiceId = $request->integer('shoot_service_id') ?: null;
        if ($shootServiceId && ! $shoot->serviceItems()->whereKey($shootServiceId)->exists()) {
            return response()->json(['message' => 'Selected service item does not belong to this shoot'], 422);
        }

        $completedFiles = $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_COMPLETED)
            ->when($shootServiceId, fn ($query) => $query->where('shoot_service_id', $shootServiceId))
            ->get();
        $rawFiles = $shoot->files()
            ->where('workflow_stage', ShootFile::STAGE_TODO)
            ->when($shootServiceId, fn ($query) => $query->where('shoot_service_id', $shootServiceId))
            ->get();
        $hasEditedWithoutRaw = $completedFiles->isNotEmpty() && $rawFiles->isEmpty();
        // Explicit fast-forward delivery is valid only for an eligible whole
        // shoot. Never trust the request flag by itself: a forged flag on a
        // standard/billable shoot must still fail closed.
        $allowNoMediaDelivery = $request->boolean('allow_no_media_delivery')
            && ! $shootServiceId
            && $shoot->allowsNoMediaDelivery();
        $allowedStatuses = [Shoot::STATUS_EDITING, Shoot::STATUS_READY, Shoot::STATUS_UPLOADED];

        if (! in_array($shoot->workflow_status, $allowedStatuses, true) && ! $hasEditedWithoutRaw && ! $allowNoMediaDelivery) {
            return response()->json([
                'message' => 'Shoot can only be finalized from editing/ready/uploaded status, when edited files exist without raw files, or with explicit no-media (fast-forward) delivery',
                'current_status' => $shoot->workflow_status,
            ], 400);
        }

        if ($completedFiles->isEmpty() && ! $allowNoMediaDelivery) {
            return response()->json([
                'message' => 'No edited files to finalize',
                'data' => $shoot->only(['id', 'workflow_status']),
            ], 400);
        }

        try {
            $shoot->workflowLogs()->create([
                'user_id' => $user->id,
                'action' => 'finalize_queued',
                'details' => 'Finalize queued for background processing',
                'metadata' => [
                    'queued_by' => $user->id,
                    'queued_at' => now()->toISOString(),
                    'completed_file_count' => $completedFiles->count(),
                    'final_status' => $request->input('final_status'),
                    'shoot_service_id' => $shootServiceId,
                    'allow_no_media_delivery' => $allowNoMediaDelivery,
                    'shoot_type' => $shoot->shoot_type,
                    'product_status' => $shoot->product_status,
                ],
            ]);

            // Seed the progress document before dispatching so the client can
            // start polling immediately, even while the job is still waiting
            // for a worker.
            $this->finalizeProgress->start((int) $shoot->id, [
                'queued_by' => $user->id,
                'completed_file_count' => $completedFiles->count(),
                'shoot_service_id' => $shootServiceId,
                'allow_no_media_delivery' => $allowNoMediaDelivery,
            ]);

            FinalizeShootJob::dispatch((int) $shoot->id, (int) $user->id, $request->input('final_status'), $shootServiceId, $allowNoMediaDelivery);

            return response()->json([
                'message' => 'Finalize started in background',
                'data' => [
                    'id' => $shoot->id,
                    'workflow_status' => $shoot->workflow_status,
                    'queued' => true,
                ],
                'progress' => $this->finalizeProgress->get((int) $shoot->id),
            ], 202);
        } catch (\Exception $e) {
            $this->finalizeProgress->fail((int) $shoot->id, 'Failed to queue finalize job: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to queue finalize job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Live progress for the finalize pipeline (the queued delivery commit plus
     * its fan-out of local caching / MLS publish / client notification jobs).
     * Returns `data: null` when nothing is tracked for the shoot so the client
     * can fall back to plain status polling.
     */
    public function finalizeProgress(Shoot $shoot)
    {
        $user = auth()->user();
        if (! $user || ! in_array($user->role, self::FINALIZE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->finalizeProgress->get((int) $shoot->id),
        ]);
    }

    public function getOrCreateInvoice(Shoot $shoot)
    {
        $user = auth()->user();
        if (! $this->invoiceAuthorization->canViewShootInvoice($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $invoice = $this->invoiceAdjustments->preferredClientInvoiceForShoot($shoot);
            if ($invoice) {
                $invoice->load([
                    'payments',
                    'shoot.client',
                    'shoot.photographer',
                    'shoot.services',
                    'shoot.payments',
                    'items',
                    'client',
                    'photographer',
                ]);
            }

            if (! $invoice) {
                $invoice = $this->invoiceService->generateForShoot($shoot);
            }

            if (! $invoice) {
                return response()->json(['message' => 'Failed to generate invoice'], 500);
            }

            return response()->json([
                'data' => $invoice
                    ->load([
                        'payments',
                        'shoot.client',
                        'shoot.photographer',
                        'shoot.services',
                        'shoot.payments',
                        'items',
                        'client',
                        'photographer',
                    ])
                    ->applyResolvedPaymentMetadata(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting/creating invoice for shoot', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to get or create invoice'], 500);
        }
    }

    public function markAsPaid(Request $request, Shoot $shoot)
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|string|in:manual,square,check,cash,bank_transfer,zelle,ach,other',
            'payment_details' => 'nullable|array',
            'payment_date' => 'nullable|date',
            'shoot_service_ids' => 'nullable|array',
            'shoot_service_ids.*' => 'integer|exists:shoot_service,id',
            'allocations' => 'nullable|array',
            'allocations.*.shoot_service_id' => 'required_with:allocations|integer|exists:shoot_service,id',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0.01',
            'allocation_strategy' => 'nullable|string|in:oldest_unpaid,manual,selected_service,selected_services',
        ]);

        try {
            $invoice = $this->findClientInvoiceForShoot($shoot);
            if (! $invoice) {
                $invoice = $this->invoiceService->generateForShoot($shoot);
            }

            $currentPaid = $shoot->fresh(['payments'])?->calculateCanonicalTotalPaid() ?? $shoot->calculateCanonicalTotalPaid();
            $remainingBalance = max(((float) ($shoot->total_quote ?? 0)) - $currentPaid, 0);
            $amount = array_key_exists('amount', $validated)
                ? round((float) $validated['amount'], 2)
                : $remainingBalance;
            $paymentType = $validated['payment_type'] ?? 'manual';
            $paymentDetails = $validated['payment_details'] ?? null;
            $paymentDate = $validated['payment_date'] ?? null;
            $paymentMethod = match ($paymentType) {
                'bank_transfer' => 'ach',
                'manual' => 'other',
                default => $paymentType,
            };

            if ($paymentMethod === 'other') {
                $notes = is_array($paymentDetails) ? ($paymentDetails['notes'] ?? null) : null;
                if (! $notes) {
                    if ($paymentType === 'manual') {
                        $paymentDetails = ['notes' => 'Legacy manual payment'];
                    } else {
                        return response()->json(['message' => 'Payment notes are required for Other payments'], 422);
                    }
                }
            }

            if ($paymentMethod === 'check' && ! (is_array($paymentDetails) ? ($paymentDetails['check_number'] ?? null) : null)) {
                return response()->json(['message' => 'Check number is required for check payments'], 422);
            }

            if (in_array($paymentMethod, ['check', 'ach'], true) && ! $paymentDate) {
                return response()->json(['message' => 'Payment date is required for check and ACH payments'], 422);
            }

            $processedAt = $paymentDate ? Carbon::parse($paymentDate) : now();
            if ($amount <= 0) {
                $amount = $remainingBalance;
            }

            if ($remainingBalance > 0 && $amount > ($remainingBalance + 0.01)) {
                return response()->json([
                    'message' => 'Payment amount cannot exceed the remaining balance',
                    'data' => [
                        'remaining_balance' => round($remainingBalance, 2),
                    ],
                ], 422);
            }

            if ($this->serviceItemSupport->requiresExplicitAllocation($shoot->fresh(['payments']), $amount, $validated)) {
                return response()->json([
                    'message' => 'Custom partial payments must target selected services, explicit allocations, or an allocation strategy.',
                ], 422);
            }

            if ($amount <= 0) {
                $paymentSummary = $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords($paymentMethod)
                    ?? $shoot->syncPaymentStatusFromRecords($paymentMethod);
                $latestPayment = $shoot->fresh(['payments'])?->getCanonicalCompletedPayments()->sortByDesc('processed_at')->first()
                    ?? $shoot->getCanonicalCompletedPayments()->sortByDesc('processed_at')->first();
                $this->syncClientInvoiceFromShootPayment(
                    $invoice,
                    $shoot,
                    $latestPayment,
                    $paymentSummary['total_paid'],
                    $paymentMethod,
                    $latestPayment?->payment_details,
                    $latestPayment?->processed_at ?? now()
                );

                return response()->json([
                    'message' => 'Shoot is already fully paid',
                    'data' => [
                        'total_paid' => $paymentSummary['total_paid'],
                        'payment_status' => $paymentSummary['payment_status'],
                    ],
                ]);
            }

            $payment = Payment::create([
                'shoot_id' => $shoot->id,
                'invoice_id' => $invoice?->id,
                'amount' => $amount,
                'currency' => 'USD',
                'payment_method' => $paymentMethod,
                'payment_details' => $paymentDetails,
                'status' => Payment::STATUS_COMPLETED,
                'processed_at' => $processedAt,
            ]);

            $finalized = $this->finalizeCompletedPayment(
                $shoot,
                $invoice,
                $payment,
                $paymentMethod,
                $paymentDetails,
                $processedAt,
                $validated,
                'payment_marked_paid'
            );

            return response()->json([
                'message' => 'Shoot marked as paid successfully',
                'data' => [
                    'payment_id' => $payment->id,
                    'total_paid' => $finalized['total_paid'],
                    'payment_status' => $finalized['payment_status'],
                    'service_items' => $this->serviceItemSupport->summaries($shoot->fresh()),
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error marking shoot as paid', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to mark shoot as paid',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apply post-create side effects for a completed Payment row: allocate to
     * service items, recompute totals, sync invoice, log activity, clear caches,
     * and send the shoot-paid email. Used by markAsPaid AND confirmIntent.
     *
     * @return array{total_paid: float, payment_status: string}
     */
    private function finalizeCompletedPayment(
        Shoot $shoot,
        ?Invoice $invoice,
        Payment $payment,
        string $paymentMethod,
        mixed $paymentDetails,
        Carbon $processedAt,
        array $allocationContext,
        string $activityAction
    ): array {
        $this->serviceItemSupport->allocatePayment($payment, $shoot->fresh(), $allocationContext);

        $oldPaymentStatus = $shoot->payment_status;
        $paymentSummary = $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords($paymentMethod)
            ?? $shoot->syncPaymentStatusFromRecords($paymentMethod);
        $totalPaid = $paymentSummary['total_paid'];
        $newPaymentStatus = $paymentSummary['payment_status'];

        $this->syncClientInvoiceFromShootPayment(
            $invoice,
            $shoot,
            $payment,
            $totalPaid,
            $paymentMethod,
            $paymentDetails,
            $processedAt
        );

        try {
            $this->activityLogger->log(
                $shoot,
                $activityAction,
                [
                    'payment_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'payment_method' => $paymentMethod,
                    'total_paid' => $totalPaid,
                    'total_quote' => $shoot->total_quote,
                    'old_status' => $oldPaymentStatus,
                    'new_status' => $newPaymentStatus,
                    'marked_by' => auth()->user()->name ?? 'Unknown',
                ],
                auth()->user()
            );
        } catch (\Exception $logError) {
            Log::warning('Failed to log activity for payment', [
                'shoot_id' => $shoot->id,
                'error' => $logError->getMessage(),
            ]);
        }

        $this->shootPaymentStatusSupport->clearShootCachesAfterPayment($shoot);
        if ($newPaymentStatus === 'paid') {
            app(PublicPaymentAccessTokenService::class)->revokeTokensForShoot($shoot);
        }

        try {
            $receiptShoot = $shoot->fresh(['client', 'payments', 'services.category']) ?? $shoot;
            $receiptPayment = $payment->fresh() ?? $payment;

            if ($receiptShoot->client) {
                $queued = $this->mailService->sendPaymentConfirmationEmail(
                    $receiptShoot->client,
                    $receiptShoot,
                    $receiptPayment
                );

                if (! $queued) {
                    Log::warning('Payment confirmation email was not queued', [
                        'shoot_id' => $shoot->id,
                        'payment_id' => $payment->id,
                    ]);
                }
            }
        } catch (\Throwable $emailError) {
            Log::warning('Failed to queue payment confirmation email', [
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'error' => $emailError->getMessage(),
            ]);
        }

        return [
            'total_paid' => (float) $totalPaid,
            'payment_status' => (string) $newPaymentStatus,
        ];
    }

    /**
     * Client / admin / rep submits an offline payment intent (cash or cheque).
     * The shoot is NOT marked paid; admin must later confirm or decline it.
     */
    public function createIntent(Request $request, Shoot $shoot)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ($this->authorizationSupport->isClientUser($user)) {
            if (! $this->authorizationSupport->canClientAccessShoot($shoot, $user)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } elseif (! $this->authorizationSupport->hasRole($user, [
            'admin', 'superadmin', 'salesRep', 'rep', 'representative', 'editing_manager',
        ])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:cash,check',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'nullable|date',
            'payment_details' => 'nullable|array',
            'payment_details.check_number' => 'nullable|string|max:64',
            'payment_details.notes' => 'nullable|string|max:2000',
        ]);

        $paymentMethod = $validated['payment_method'];
        $amount = round((float) $validated['amount'], 2);
        $paymentDetails = is_array($validated['payment_details'] ?? null) ? $validated['payment_details'] : [];
        $checkNumber = trim((string) ($paymentDetails['check_number'] ?? ''));
        $notes = isset($paymentDetails['notes']) ? trim((string) $paymentDetails['notes']) : null;

        if ($paymentMethod === 'check' && $checkNumber === '') {
            return response()->json(['message' => 'Cheque number is required for cheque payments.'], 422);
        }

        $paymentDate = $validated['payment_date'] ?? null;
        if ($paymentMethod === 'check' && ! $paymentDate) {
            return response()->json(['message' => 'Cheque date is required for cheque payments.'], 422);
        }

        $shoot = $shoot->fresh(['payments']);
        $totalQuote = (float) ($shoot->total_quote ?? 0);
        $totalPaid = $shoot->calculateCanonicalTotalPaid();
        $pendingTotal = $this->pendingIntentTotal($shoot);
        $maxAllowed = max($totalQuote - $totalPaid - $pendingTotal, 0);

        if ($maxAllowed <= 0.01) {
            return response()->json([
                'message' => 'No outstanding balance available. Existing pending intents already cover the remaining amount.',
            ], 422);
        }

        if ($amount > ($maxAllowed + 0.01)) {
            return response()->json([
                'message' => 'Amount exceeds the available balance after pending intents.',
                'data' => [
                    'available_balance' => round($maxAllowed, 2),
                    'pending_total' => round($pendingTotal, 2),
                ],
            ], 422);
        }

        $detailsToStore = [];
        if ($checkNumber !== '') {
            $detailsToStore['check_number'] = $checkNumber;
        }
        if ($paymentDate) {
            $detailsToStore['payment_date'] = (string) $paymentDate;
        }
        if ($notes) {
            $detailsToStore['notes'] = $notes;
        }
        $detailsToStore['submitted_by_user_id'] = (int) $user->id;
        $detailsToStore['submitted_by_role'] = (string) ($user->role ?? '');
        $detailsToStore['submitted_by_name'] = (string) ($user->name ?? '');
        $detailsToStore['submitted_at'] = now()->toIso8601String();

        $invoice = $this->findClientInvoiceForShoot($shoot);
        if (! $invoice) {
            try {
                $invoice = $this->invoiceService->generateForShoot($shoot);
            } catch (\Throwable $e) {
                Log::warning('Failed to ensure invoice during payment intent creation', [
                    'shoot_id' => $shoot->id,
                    'error' => $e->getMessage(),
                ]);
                $invoice = null;
            }
        }

        $payment = Payment::create([
            'shoot_id' => $shoot->id,
            'invoice_id' => $invoice?->id,
            'amount' => $amount,
            'currency' => 'USD',
            'payment_method' => $paymentMethod,
            'payment_details' => $detailsToStore,
            'status' => Payment::STATUS_PENDING,
            'processed_at' => null,
        ]);

        try {
            $this->activityLogger->log(
                $shoot,
                'payment_intent_submitted',
                [
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'payment_method' => $paymentMethod,
                    'submitted_by' => $user->name ?? '',
                    'submitted_by_role' => $user->role ?? '',
                ],
                $user
            );
        } catch (\Throwable $logError) {
            Log::warning('Failed to log payment intent submission', [
                'shoot_id' => $shoot->id,
                'error' => $logError->getMessage(),
            ]);
        }

        try {
            $this->mailService->sendOfflinePaymentIntentSubmittedEmail($shoot, $payment, $user);
        } catch (\Throwable $emailError) {
            Log::warning('Failed to send offline payment intent submitted email', [
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'error' => $emailError->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Payment submitted. Awaiting admin confirmation.',
            'data' => $this->stripePaymentMetadataService->serializePayment($payment),
        ], 201);
    }

    /**
     * Admin/rep confirms a previously submitted offline payment intent,
     * promoting it to a completed payment with full side effects.
     */
    public function confirmIntent(Request $request, Shoot $shoot, Payment $payment)
    {
        $user = auth()->user();
        if (! $this->authorizationSupport->hasRole($user, [
            'admin', 'superadmin', 'salesRep', 'rep', 'representative',
        ])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $payment->shoot_id !== (int) $shoot->id) {
            return response()->json(['message' => 'Payment does not belong to this shoot.'], 404);
        }

        $validated = $request->validate([
            'payment_date' => 'nullable|date',
        ]);

        try {
            return DB::transaction(function () use ($shoot, $payment, $validated) {
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
                if (! $locked) {
                    return response()->json(['message' => 'Payment not found.'], 404);
                }

                if ($locked->status !== Payment::STATUS_PENDING) {
                    return response()->json([
                        'message' => 'Only pending payment intents can be confirmed.',
                        'data' => $this->stripePaymentMetadataService->serializePayment($locked),
                    ], 409);
                }

                if (! in_array((string) $locked->payment_method, self::INTENT_METHODS, true)) {
                    return response()->json(['message' => 'Unsupported intent payment method.'], 422);
                }

                $totalQuote = (float) ($shoot->total_quote ?? 0);
                $currentPaid = $shoot->fresh(['payments'])?->calculateCanonicalTotalPaid() ?? 0;
                $remainingBalance = max($totalQuote - $currentPaid, 0);
                $amount = round((float) $locked->amount, 2);
                if ($remainingBalance > 0 && $amount > ($remainingBalance + 0.01)) {
                    return response()->json([
                        'message' => 'Pending payment exceeds remaining balance. Decline and re-record a smaller amount.',
                        'data' => [
                            'remaining_balance' => round($remainingBalance, 2),
                        ],
                    ], 422);
                }

                $details = is_array($locked->payment_details) ? $locked->payment_details : [];
                $details['confirmed_by_user_id'] = (int) auth()->id();
                $details['confirmed_by_name'] = (string) (auth()->user()?->name ?? '');
                $details['confirmed_at'] = now()->toIso8601String();

                $processedAt = ! empty($validated['payment_date'])
                    ? Carbon::parse($validated['payment_date'])
                    : (isset($details['payment_date']) ? Carbon::parse($details['payment_date']) : now());

                $locked->status = Payment::STATUS_COMPLETED;
                $locked->payment_details = $details;
                $locked->processed_at = $processedAt;
                $locked->save();

                $invoice = $this->findClientInvoiceForShoot($shoot);
                if (! $invoice) {
                    $invoice = $this->invoiceService->generateForShoot($shoot);
                }

                $finalized = $this->finalizeCompletedPayment(
                    $shoot,
                    $invoice,
                    $locked,
                    (string) $locked->payment_method,
                    $locked->payment_details,
                    $processedAt,
                    [],
                    'payment_intent_confirmed'
                );

                return response()->json([
                    'message' => 'Payment confirmed.',
                    'data' => [
                        'payment' => $this->stripePaymentMetadataService->serializePayment($locked->fresh()),
                        'total_paid' => $finalized['total_paid'],
                        'payment_status' => $finalized['payment_status'],
                    ],
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to confirm payment intent', [
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to confirm payment intent.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin/rep declines a pending intent, marks it failed, and notifies client.
     */
    public function declineIntent(Request $request, Shoot $shoot, Payment $payment)
    {
        $user = auth()->user();
        if (! $this->authorizationSupport->hasRole($user, [
            'admin', 'superadmin', 'salesRep', 'rep', 'representative',
        ])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $payment->shoot_id !== (int) $shoot->id) {
            return response()->json(['message' => 'Payment does not belong to this shoot.'], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        if ($payment->status !== Payment::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending payment intents can be declined.',
            ], 409);
        }

        $details = is_array($payment->payment_details) ? $payment->payment_details : [];
        $details['decline_reason'] = $validated['reason'] ?? null;
        $details['declined_by_user_id'] = (int) auth()->id();
        $details['declined_by_name'] = (string) (auth()->user()?->name ?? '');
        $details['declined_at'] = now()->toIso8601String();

        $payment->status = Payment::STATUS_FAILED;
        $payment->payment_details = $details;
        $payment->save();

        try {
            $this->activityLogger->log(
                $shoot,
                'payment_intent_declined',
                [
                    'payment_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'payment_method' => (string) $payment->payment_method,
                    'reason' => $details['decline_reason'],
                    'declined_by' => $details['declined_by_name'],
                ],
                $user
            );
        } catch (\Throwable $logError) {
            Log::warning('Failed to log payment intent decline', [
                'shoot_id' => $shoot->id,
                'error' => $logError->getMessage(),
            ]);
        }

        try {
            if ($shoot->client) {
                $this->mailService->sendOfflinePaymentIntentDeclinedEmail($shoot, $payment, $details['decline_reason'] ?? null);
            }
        } catch (\Throwable $emailError) {
            Log::warning('Failed to send offline payment intent declined email', [
                'shoot_id' => $shoot->id,
                'payment_id' => $payment->id,
                'error' => $emailError->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Payment intent declined.',
            'data' => $this->stripePaymentMetadataService->serializePayment($payment->fresh()),
        ]);
    }

    private function pendingIntentTotal(Shoot $shoot): float
    {
        $payments = $shoot->relationLoaded('payments')
            ? $shoot->payments
            : $shoot->payments()->get();

        return (float) $payments
            ->filter(fn (Payment $payment) => $payment->status === Payment::STATUS_PENDING
                && in_array((string) $payment->payment_method, self::INTENT_METHODS, true))
            ->sum(fn (Payment $payment) => (float) $payment->amount);
    }

    private function serializePendingIntents(Shoot $shoot): array
    {
        $payments = $shoot->relationLoaded('payments')
            ? $shoot->payments
            : $shoot->payments()->get();

        return $payments
            ->filter(fn (Payment $payment) => $payment->status === Payment::STATUS_PENDING
                && in_array((string) $payment->payment_method, self::INTENT_METHODS, true))
            ->map(fn (Payment $payment) => $this->stripePaymentMetadataService->serializePayment($payment))
            ->values()
            ->all();
    }

    private function findClientInvoiceForShoot(Shoot $shoot): ?Invoice
    {
        return $this->invoiceAdjustments->preferredClientInvoiceForShoot($shoot);
    }

    private function syncClientInvoiceFromShootPayment(
        ?Invoice $invoice,
        Shoot $shoot,
        ?Payment $payment,
        float $shootTotalPaid,
        ?string $paymentMethod,
        mixed $paymentDetails,
        Carbon $processedAt
    ): void {
        $invoice ??= $this->findClientInvoiceForShoot($shoot);
        if (! $invoice) {
            return;
        }

        $this->invoiceAdjustments->reconcileClientInvoicesForShoot(
            $shoot,
            $payment,
            $paymentMethod,
            $paymentDetails
        );
    }

    public function getPaymentDetails(Shoot $shoot)
    {
        $user = auth()->user();
        if (! $user || ! $this->authorizationSupport->canAccessShootMedia($shoot, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shoot->load(['client', 'services', 'payments']);
        $shoot = $this->shootPaymentStatusSupport->reconcileStripePaymentState($shoot, ['client', 'services', 'payments']);

        return response()->json([
            'data' => $this->buildPaymentDetailsPayload($shoot, true),
        ]);
    }

    public function getPublicPaymentDetails(string $token)
    {
        $accessToken = app(PublicPaymentAccessTokenService::class)->resolveAccessibleToken($token);
        if (! $accessToken) {
            return response()->json([
                'message' => 'This payment link is unavailable.',
            ], 410);
        }

        $accessToken->markAccessed();
        $shoot = $this->shootPaymentStatusSupport->reconcileStripePaymentState(
            $accessToken->shoot->load(['services', 'payments']),
            ['services', 'payments']
        );

        return response()->json([
            'data' => array_merge(
                $this->buildPaymentDetailsPayload($shoot, false),
                [
                    'token_expires_at' => $accessToken->expires_at?->toIso8601String(),
                ]
            ),
        ]);
    }

    protected function buildPaymentDetailsPayload(Shoot $shoot, bool $includeClient): array
    {
        $payments = ($shoot->payments ?? collect())->map(function ($payment) {
            if (! $payment instanceof Payment) {
                return $payment;
            }

            return $this->stripePaymentMetadataService->hydratePaymentRecordIfNeeded($payment);
        });
        $latestReceiptPayment = $this->stripePaymentMetadataService->resolveLatestReceiptPayment($payments);
        $orderItems = $this->serviceItemSupport->summaries($shoot);
        $invoiceAdjustmentTotal = collect($orderItems)
            ->filter(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
            ->sum(fn ($item) => (float) ($item['total_amount'] ?? $item['subtotal'] ?? 0));
        $services = $shoot->services->map(fn ($service) => [
            'name' => $service->name,
            'shoot_service_id' => $service->pivot->id ?? null,
            'pivot' => [
                'price' => (float) ($service->pivot->price ?? $service->price ?? 0),
                'quantity' => (int) ($service->pivot->quantity ?? 1),
            ],
        ])->values();
        $adjustmentServices = collect($orderItems)
            ->filter(fn ($item) => (bool) ($item['is_invoice_adjustment'] ?? false))
            ->map(fn ($item) => [
                'name' => $item['name'],
                'shoot_service_id' => null,
                'invoice_item_id' => $item['invoice_item_id'],
                'is_invoice_adjustment' => true,
                'charge_type' => $item['charge_type'],
                'pivot' => [
                    'price' => (float) $item['unit_amount'],
                    'quantity' => (int) $item['quantity'],
                ],
            ]);

        return [
            'id' => $shoot->id,
            'address' => $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'zip' => $shoot->zip,
            'scheduled_date' => $shoot->scheduled_date?->toISOString(),
            'time' => $shoot->time,
            'total_quote' => (float) ($shoot->total_quote ?? 0),
            'service_subtotal' => (float) (($shoot->base_quote ?? 0) + ($shoot->discount_amount ?? 0)),
            'base_quote' => (float) ($shoot->base_quote ?? 0),
            'discount_type' => $shoot->discount_type,
            'discount_value' => $shoot->discount_value !== null ? (float) $shoot->discount_value : null,
            'discount_amount' => (float) ($shoot->discount_amount ?? 0),
            'discounted_subtotal' => (float) ($shoot->base_quote ?? 0),
            'invoice_adjustments_total' => round($invoiceAdjustmentTotal, 2),
            'order_total' => (float) ($shoot->total_quote ?? 0),
            'tax_amount' => (float) ($shoot->tax_amount ?? 0),
            'services' => $services->merge($adjustmentServices)->values()->all(),
            'service_items' => $orderItems,
            'serviceItems' => $orderItems,
            'order_items' => $orderItems,
            'orderItems' => $orderItems,
            'payments' => $payments
                ->filter(fn ($payment) => $payment instanceof Payment)
                ->map(fn (Payment $payment) => $this->stripePaymentMetadataService->serializePayment($payment))
                ->values()
                ->all(),
            'pending_payments' => $this->serializePendingIntents($shoot),
            'pending_total' => round($this->pendingIntentTotal($shoot), 2),
            'payment_status' => $shoot->payment_status,
            'amount_due' => max((float) ($shoot->total_quote ?? 0) - $shoot->calculateCanonicalTotalPaid(), 0),
            'receipt' => $this->stripePaymentMetadataService->buildReceiptPayload($latestReceiptPayment),
            'client' => $includeClient && $shoot->client ? [
                'name' => $shoot->client->name,
                'email' => $shoot->client->email,
            ] : null,
        ];
    }
}
