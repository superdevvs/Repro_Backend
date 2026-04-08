<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\FinalizeShootJob;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootPaymentStatusSupport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShootPaymentsController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected MailService $mailService,
        protected ShootActivityLogger $activityLogger,
        protected ShootPaymentStatusSupport $shootPaymentStatusSupport
    ) {
    }

    public function finalize(Request $request, $shootId)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'final_status' => 'nullable|string|in:admin_verified,completed',
        ]);

        $shoot = Shoot::with(['files'])->findOrFail($shootId);
        $completedFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_COMPLETED)->get();
        $rawFiles = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO)->get();
        $hasEditedWithoutRaw = $completedFiles->isNotEmpty() && $rawFiles->isEmpty();
        $allowedStatuses = [Shoot::STATUS_EDITING, Shoot::STATUS_READY, Shoot::STATUS_UPLOADED];

        if (!in_array($shoot->workflow_status, $allowedStatuses, true) && !$hasEditedWithoutRaw) {
            return response()->json([
                'message' => 'Shoot can only be finalized from editing/ready/uploaded status, or when edited files exist without raw files',
                'current_status' => $shoot->workflow_status,
            ], 400);
        }

        if ($completedFiles->isEmpty()) {
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
                ],
            ]);

            FinalizeShootJob::dispatch((int) $shoot->id, (int) $user->id, $request->input('final_status'));

            return response()->json([
                'message' => 'Finalize started in background',
                'data' => [
                    'id' => $shoot->id,
                    'workflow_status' => $shoot->workflow_status,
                    'queued' => true,
                ],
            ], 202);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to queue finalize job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOrCreateInvoice(Shoot $shoot)
    {
        $user = auth()->user();
        if (!$user || $user->role === 'editor') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $invoice = Invoice::where('shoot_id', $shoot->id)
                ->with([
                    'payments',
                    'shoot.client',
                    'shoot.photographer',
                    'shoot.services',
                    'shoot.payments',
                    'items',
                    'client',
                    'photographer',
                ])
                ->first();

            if (!$invoice) {
                $invoice = $this->invoiceService->generateForShoot($shoot);
            }

            if (!$invoice) {
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
        ]);

        try {
            $invoice = $this->findClientInvoiceForShoot($shoot);
            if (!$invoice) {
                $invoice = $this->invoiceService->generateForShoot($shoot);
            }

            $amount = $validated['amount'] ?? $shoot->total_quote ?? 0;
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
                if (!$notes) {
                    if ($paymentType === 'manual') {
                        $paymentDetails = ['notes' => 'Legacy manual payment'];
                    } else {
                        return response()->json(['message' => 'Payment notes are required for Other payments'], 422);
                    }
                }
            }

            if ($paymentMethod === 'check' && !(is_array($paymentDetails) ? ($paymentDetails['check_number'] ?? null) : null)) {
                return response()->json(['message' => 'Check number is required for check payments'], 422);
            }

            if (in_array($paymentMethod, ['check', 'ach'], true) && !$paymentDate) {
                return response()->json(['message' => 'Payment date is required for check and ACH payments'], 422);
            }

            $processedAt = $paymentDate ? Carbon::parse($paymentDate) : now();
            if ($amount <= 0) {
                $currentPaid = $shoot->fresh(['payments'])?->calculateCanonicalTotalPaid() ?? $shoot->calculateCanonicalTotalPaid();
                $amount = max(($shoot->total_quote ?? 0) - $currentPaid, 0);
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
                    'payment_marked_paid',
                    [
                        'payment_id' => $payment->id,
                        'amount' => $amount,
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

            try {
                if ($shoot->client) {
                    $this->mailService->sendShootPaidEmail($shoot->client, $shoot, $amount);
                }
            } catch (\Throwable $emailError) {
                Log::warning('Failed to send shoot paid email', [
                    'shoot_id' => $shoot->id,
                    'error' => $emailError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Shoot marked as paid successfully',
                'data' => [
                    'payment_id' => $payment->id,
                    'total_paid' => $totalPaid,
                    'payment_status' => $newPaymentStatus,
                ],
            ]);
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

    private function findClientInvoiceForShoot(Shoot $shoot): ?Invoice
    {
        return Invoice::query()
            ->where('shoot_id', $shoot->id)
            ->where(function ($query) use ($shoot) {
                $query->where('role', Invoice::ROLE_CLIENT);

                if ($shoot->client_id) {
                    $query->orWhere('client_id', $shoot->client_id);
                }
            })
            ->orderByDesc('id')
            ->first();
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
        if (!$invoice) {
            return;
        }

        $invoiceTotal = (float) ($invoice->total ?? $invoice->total_amount ?? $shoot->total_quote ?? 0);
        $amountPaid = round(min($shootTotalPaid, $invoiceTotal > 0 ? $invoiceTotal : $shootTotalPaid), 2);
        $isPaid = $invoiceTotal > 0
            ? $amountPaid >= ($invoiceTotal - 0.01)
            : $amountPaid > 0;

        $invoice->amount_paid = $amountPaid;
        $invoice->is_paid = $isPaid;
        $invoice->status = $isPaid
            ? Invoice::STATUS_PAID
            : (($invoice->status ?? Invoice::STATUS_SENT) === Invoice::STATUS_DRAFT
                ? Invoice::STATUS_SENT
                : ($invoice->status ?? Invoice::STATUS_SENT));
        $invoice->paid_at = $isPaid ? $processedAt : null;

        if ($paymentMethod !== null && $paymentMethod !== '') {
            $invoice->payment_method = $paymentMethod;
            $invoice->payment_details = is_array($paymentDetails) ? $paymentDetails : null;
        }

        $invoice->save();

        if ($payment && (int) $payment->invoice_id !== (int) $invoice->id) {
            $payment->invoice_id = $invoice->id;
            $payment->save();
        }
    }

    public function getPaymentDetails(Shoot $shoot)
    {
        $shoot->load(['client', 'services', 'payments']);
        $shoot = $this->shootPaymentStatusSupport->reconcileStripePaymentState($shoot, ['client', 'services', 'payments']);

        return response()->json([
            'data' => [
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
                'tax_amount' => (float) ($shoot->tax_amount ?? 0),
                'services' => $shoot->services->map(fn ($service) => [
                    'name' => $service->name,
                    'pivot' => [
                        'price' => (float) ($service->pivot->price ?? $service->price ?? 0),
                        'quantity' => (int) ($service->pivot->quantity ?? 1),
                    ],
                ]),
                'client' => $shoot->client ? [
                    'name' => $shoot->client->name,
                    'email' => $shoot->client->email,
                ] : null,
                'payments' => $shoot->payments->map(fn ($payment) => [
                    'amount' => (float) $payment->amount,
                    'status' => $payment->status,
                ]),
            ],
        ]);
    }
}
