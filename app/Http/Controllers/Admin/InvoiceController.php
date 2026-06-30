<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoiceService)
    {
    }

    public function index(Request $request)
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $query = Invoice::query()->with('user');

        if ($request->boolean('with_items')) {
            $query->with('items');
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        $invoices = $query
            ->orderByDesc('period_start')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($invoices);
    }

    public function show(Invoice $invoice)
    {
        return response()->json(
            $invoice
                ->load([
                    'items',
                    'user',
                    'client',
                    'photographer',
                    'payments',
                    'shoot',
                    'shoot.client',
                    'shoot.photographer',
                    'shoot.payments',
                    'shoots',
                    'shoots.client',
                    'shoots.photographer',
                    'shoots.payments',
                ])
                ->applyResolvedPaymentMetadata()
        );
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['required', 'in:' . implode(',', [Invoice::ROLE_CLIENT, Invoice::ROLE_PHOTOGRAPHER])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $user = User::findOrFail($data['user_id']);

        $invoice = $this->invoiceService->generateInvoice(
            $user,
            $data['role'],
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end'])
        )->loadMissing('user');

        return response()->json([
            'message' => 'Invoice generated successfully.',
            'data' => $this->serializeGeneratedInvoice($invoice),
        ], 201);
    }

    public function send(Invoice $invoice)
    {
        $invoice->markSent();

        return response()->json([
            'message' => 'Invoice marked as sent.',
            'data' => $invoice->fresh(['items', 'user']),
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'paid_at' => ['nullable', 'date'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'in:square,zelle,cash,check,ach,other,manual,bank_transfer'],
            'payment_details' => ['nullable', 'array'],
        ]);

        $paymentType = $data['payment_method'] ?? null;
        $paymentDetails = $data['payment_details'] ?? null;
        $paymentMethod = $paymentType
            ? match ($paymentType) {
                'bank_transfer' => 'ach',
                'manual' => 'other',
                default => $paymentType,
            }
            : null;

        if ($paymentMethod === 'other') {
            $notes = is_array($paymentDetails) ? ($paymentDetails['notes'] ?? null) : null;
            if (!$notes) {
                if ($paymentType === 'manual') {
                    $paymentDetails = ['notes' => 'Legacy manual payment'];
                } else {
                    return response()->json([
                        'message' => 'Payment notes are required for Other payments',
                    ], 422);
                }
            }
        }

        if ($paymentMethod === 'check') {
            $checkNumber = is_array($paymentDetails) ? ($paymentDetails['check_number'] ?? null) : null;
            if (!$checkNumber) {
                return response()->json([
                    'message' => 'Check number is required for check payments',
                ], 422);
            }
        }

        if ($paymentMethod && in_array($paymentMethod, ['check', 'ach'], true) && empty($data['paid_at'])) {
            return response()->json([
                'message' => 'Payment date is required for check and ACH payments',
            ], 422);
        }

        $invoiceTotal = round((float) ($invoice->total ?? $invoice->total_amount ?? 0), 2);
        $currentPaid = round($invoice->totalPaid(), 2);
        if ($currentPaid <= 0 && $invoice->getAttribute('amount_paid') !== null) {
            $currentPaid = round((float) $invoice->getAttribute('amount_paid'), 2);
        }
        $remainingBalance = round(max($invoiceTotal - $currentPaid, 0), 2);
        $paymentAmount = array_key_exists('amount_paid', $data)
            ? round((float) ($data['amount_paid'] ?? 0), 2)
            : $remainingBalance;

        if ($paymentAmount <= 0) {
            $paymentAmount = $remainingBalance;
        }

        if ($remainingBalance > 0 && $paymentAmount > ($remainingBalance + 0.01)) {
            return response()->json([
                'message' => 'Payment amount cannot exceed the remaining balance',
                'data' => [
                    'remaining_balance' => $remainingBalance,
                ],
            ], 422);
        }

        if ($remainingBalance <= 0) {
            $paymentAmount = 0.0;
        }

        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
        $amountPaid = round(
            min(
                $currentPaid + $paymentAmount,
                $invoiceTotal > 0 ? $invoiceTotal : ($currentPaid + $paymentAmount)
            ),
            2
        );
        $isPaid = $invoiceTotal > 0
            ? $amountPaid >= ($invoiceTotal - 0.01)
            : $amountPaid > 0;
        $effectivePaidAt = $paymentAmount > 0
            ? $paidAt
            : ($invoice->latestCompletedPayment()?->processed_at ?? $invoice->paid_at ?? now());

        $invoice->fill([
            'amount_paid' => $amountPaid,
            'is_paid' => $isPaid,
            'paid_at' => $isPaid ? $effectivePaidAt : null,
            'status' => $isPaid
                ? Invoice::STATUS_PAID
                : (($invoice->status ?? Invoice::STATUS_SENT) === Invoice::STATUS_DRAFT
                    ? Invoice::STATUS_SENT
                    : ($invoice->status ?? Invoice::STATUS_SENT)),
        ]);

        if ($paymentMethod !== null) {
            $invoice->payment_method = $paymentMethod;
            $invoice->payment_details = $paymentDetails;
        }

        $invoice->save();
        $this->syncShootPaymentFromInvoice($invoice, $paymentAmount, $paymentMethod, $paymentDetails, $paidAt);
        if ($isPaid) {
            $this->markPayoutShootsPaid($invoice, $paidAt);
            $invoice->recordAuditEvent('paid', $request->user(), 'Invoice payment marked as sent.', [
                'amount_paid' => $amountPaid,
                'payment_amount' => $paymentAmount,
                'payment_method' => $paymentMethod,
                'paid_at' => $paidAt->toISOString(),
            ]);
        }

        $invoice->loadMissing(['client', 'photographer', 'shoot', 'shoot.client']);
        $context = [
            'invoice' => $invoice,
            'invoice_id' => $invoice->id,
        ];
        if ($invoice->client) {
            $context['client'] = $invoice->client;
            $context['account_id'] = $invoice->client_id;
        } elseif ($invoice->photographer) {
            $context['photographer'] = $invoice->photographer;
            $context['account_id'] = $invoice->photographer_id;
        }
        app(AutomationService::class)->handleEvent('INVOICE_PAID', $context);

        // Send shoot paid email to the client for shoot-linked invoices (parity with ShootPaymentsController::markAsPaid)
        if ($paymentAmount > 0) {
            $shootForEmail = $invoice->shoot;
            $clientForEmail = $shootForEmail?->client ?? $invoice->client;
            if ($shootForEmail && $clientForEmail) {
                try {
                    app(MailService::class)->sendShootPaidEmail($clientForEmail, $shootForEmail, $paymentAmount);
                } catch (\Throwable $emailError) {
                    Log::warning('Failed to send shoot paid email from admin invoice mark-paid', [
                        'invoice_id' => $invoice->id,
                        'shoot_id' => $shootForEmail->id,
                        'error' => $emailError->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Invoice marked as paid.',
            'data' => $this->buildInvoiceResponse($invoice),
        ]);
    }

    private function syncShootPaymentFromInvoice(
        Invoice $invoice,
        float $paymentAmount,
        ?string $paymentMethod,
        mixed $paymentDetails,
        Carbon $paidAt
    ): void {
        if ($paymentAmount <= 0) {
            return;
        }

        $shoot = $invoice->shoot ?: ($invoice->shoot_id ? Shoot::find($invoice->shoot_id) : null);
        if (!$shoot) {
            return;
        }

        Payment::create([
            'shoot_id' => $shoot->id,
            'invoice_id' => $invoice->id,
            'amount' => $paymentAmount,
            'currency' => 'USD',
            'payment_method' => $paymentMethod,
            'payment_details' => is_array($paymentDetails) ? $paymentDetails : null,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => $paidAt,
        ]);

        $shoot->loadMissing('payments');
        $shoot->syncPaymentStatusFromRecords($paymentMethod ?: $shoot->payment_type);
    }

    private function markPayoutShootsPaid(Invoice $invoice, Carbon $paidAt): void
    {
        if (!in_array($invoice->role, [Invoice::ROLE_PHOTOGRAPHER, Invoice::ROLE_SALES_REP], true)) {
            return;
        }

        $invoice->loadMissing('shoots');

        foreach ($invoice->shoots as $shoot) {
            $updateData = [];

            if ($invoice->photographer_id && !$shoot->photographer_paid_at) {
                $updateData['photographer_paid_at'] = $paidAt;
                $updateData['photographer_paid_invoice_id'] = $invoice->id;
            }

            if ($invoice->sales_rep_id && !$shoot->sales_rep_paid_at) {
                $updateData['sales_rep_paid_at'] = $paidAt;
                $updateData['sales_rep_paid_invoice_id'] = $invoice->id;
            }

            if (!empty($updateData)) {
                $shoot->update($updateData);
            }
        }
    }

    private function buildInvoiceResponse(Invoice $invoice): Invoice
    {
        return $invoice
            ->fresh([
                'items',
                'user',
                'client',
                'photographer',
                'payments',
                'shoot',
                'shoot.client',
                'shoot.photographer',
                'shoot.payments',
                'shoots',
                'shoots.client',
                'shoots.photographer',
                'shoots.payments',
            ])
            ->applyResolvedPaymentMetadata();
    }

    public function addMiscItem(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            // When true the adjustment is added to the client payable (shoot.total_quote).
            // Defaults to false so adjustments never silently increase what a client owes.
            'bills_client' => ['nullable', 'boolean'],
            // Free-form classifier, e.g. "misc" or "virtual_staging".
            'charge_type' => ['nullable', 'string', 'max:50'],
            // Optional client-supplied idempotency key to prevent double-submit duplicates.
            'dedupe_key' => ['nullable', 'string', 'max:100'],
        ]);

        $quantity = $data['quantity'] ?? 1;
        $billsClient = (bool) ($data['bills_client'] ?? false);
        $chargeType = $data['charge_type'] ?? 'misc';
        $dedupeKey = $data['dedupe_key'] ?? null;
        $totalAmount = round($quantity * (float) $data['amount'], 2);

        try {
            DB::beginTransaction();

            // Idempotency guard: if an identical dedupe_key already exists on this invoice,
            // return the existing item instead of creating a duplicate (double-click / retry).
            if ($dedupeKey !== null) {
                $existing = $invoice->items()
                    ->where('type', InvoiceItem::TYPE_EXPENSE)
                    ->where('meta->source', 'admin_misc')
                    ->where('meta->dedupe_key', $dedupeKey)
                    ->first();

                if ($existing) {
                    DB::commit();

                    return response()->json([
                        'message' => 'Misc item already added',
                        'item' => $existing,
                        'invoice' => $invoice->fresh(['items', 'user']),
                    ], 200);
                }
            }

            $item = $invoice->items()->create([
                'shoot_id' => $invoice->shoot_id,
                'type' => InvoiceItem::TYPE_EXPENSE,
                'description' => $data['description'],
                'quantity' => $quantity,
                'unit_amount' => $data['amount'],
                'total_amount' => $totalAmount,
                'recorded_at' => now(),
                'meta' => array_filter([
                    'source' => 'admin_misc',
                    'bills_client' => $billsClient,
                    'charge_type' => $chargeType,
                    'dedupe_key' => $dedupeKey,
                ], fn ($value) => $value !== null),
            ]);

            $invoice->refreshTotals();
            $invoice->update([
                'modified_by' => $request->user()?->id,
                'modified_at' => now(),
            ]);

            // Only billable adjustments move the canonical client payable.
            if ($billsClient) {
                $this->syncShootPayableForBillableDelta($invoice, $totalAmount);
            }

            DB::commit();

            return response()->json([
                'message' => 'Misc item added successfully',
                'item' => $item,
                'invoice' => $invoice->fresh(['items', 'user']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add misc item to invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to add misc item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateMiscItem(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        $source = is_array($item->meta) ? ($item->meta['source'] ?? null) : null;
        if ($item->type !== InvoiceItem::TYPE_EXPENSE || $source !== 'admin_misc') {
            return response()->json(['message' => 'Item is not an admin misc item'], 422);
        }

        $data = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'bills_client' => ['nullable', 'boolean'],
            'charge_type' => ['nullable', 'string', 'max:50'],
        ]);

        $meta = is_array($item->meta) ? $item->meta : [];
        $quantity = $data['quantity'] ?? 1;
        $newBillsClient = array_key_exists('bills_client', $data)
            ? (bool) $data['bills_client']
            : (bool) ($meta['bills_client'] ?? false);
        $newChargeType = $data['charge_type'] ?? ($meta['charge_type'] ?? 'misc');
        $newTotal = round($quantity * (float) $data['amount'], 2);

        // Billable contribution before vs after so we can apply the precise payable delta.
        $oldBillable = ((bool) ($meta['bills_client'] ?? false)) ? (float) $item->total_amount : 0.0;
        $newBillable = $newBillsClient ? $newTotal : 0.0;

        try {
            DB::beginTransaction();

            $meta['source'] = 'admin_misc';
            $meta['bills_client'] = $newBillsClient;
            $meta['charge_type'] = $newChargeType;

            $item->update([
                'description' => $data['description'],
                'quantity' => $quantity,
                'unit_amount' => $data['amount'],
                'total_amount' => $newTotal,
                'meta' => $meta,
            ]);

            $invoice->refreshTotals();
            $invoice->update([
                'modified_by' => $request->user()?->id,
                'modified_at' => now(),
            ]);

            $this->syncShootPayableForBillableDelta($invoice, $newBillable - $oldBillable);

            DB::commit();

            return response()->json([
                'message' => 'Misc item updated successfully',
                'item' => $item->fresh(),
                'invoice' => $invoice->fresh(['items', 'user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update misc item on invoice', [
                'invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update misc item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Keep shoot.total_quote (the canonical client payable) in sync with billable admin
     * invoice adjustments, then recompute the shoot payment status. Display-only
     * adjustments pass a zero delta and never reach this method.
     */
    private function syncShootPayableForBillableDelta(Invoice $invoice, float $billableDelta): void
    {
        if (abs($billableDelta) < 0.01) {
            return;
        }

        $shoot = $invoice->shoot;
        if (!$shoot) {
            return;
        }

        $newTotalQuote = round(max((float) ($shoot->total_quote ?? 0) + $billableDelta, 0), 2);
        $shoot->total_quote = $newTotalQuote;

        // A shoot that now carries a real balance should not stay auto-bypassed/paid.
        if ($newTotalQuote > 0.01 && $shoot->bypass_paywall) {
            $shoot->bypass_paywall = false;
        }

        $shoot->save();
        $shoot->syncPaymentStatusFromRecords();
    }

    public function removeMiscItem(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        $source = is_array($item->meta) ? ($item->meta['source'] ?? null) : null;
        if ($item->type !== InvoiceItem::TYPE_EXPENSE || $source !== 'admin_misc') {
            return response()->json(['message' => 'Item is not an admin misc item'], 422);
        }

        try {
            DB::beginTransaction();

            $meta = is_array($item->meta) ? $item->meta : [];
            $billableContribution = ((bool) ($meta['bills_client'] ?? false)) ? (float) $item->total_amount : 0.0;

            $item->delete();
            $invoice->refreshTotals();
            $invoice->update([
                'modified_by' => $request->user()?->id,
                'modified_at' => now(),
            ]);

            // Reverse the client payable only if this adjustment was billable.
            $this->syncShootPayableForBillableDelta($invoice, -$billableContribution);

            DB::commit();

            return response()->json([
                'message' => 'Misc item removed successfully',
                'invoice' => $invoice->fresh(['items', 'user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove misc item from invoice', [
                'invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to remove misc item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGeneratedInvoice(Invoice $invoice): array
    {
        $payload = $invoice->toArray();

        foreach (['charges_total', 'payments_total', 'balance_due'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null) {
                $payload[$field] = number_format((float) $payload[$field], 2, '.', '');
            }
        }

        return $payload;
    }
}
