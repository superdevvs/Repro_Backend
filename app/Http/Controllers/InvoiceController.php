<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Invoices\InvoiceAdjustmentService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    private const ADMIN_ROLES = ['admin', 'superadmin', 'super_admin', 'editing_manager'];

    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];

    private const PAYOUT_INVOICE_ROLES = [
        Invoice::ROLE_PHOTOGRAPHER,
        Invoice::ROLE_SALES_REP,
        'sales_rep',
        'salesrep',
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $filters = $request->validate([
            'start' => ['nullable', 'date'],
            'end' => [
                'nullable',
                'date',
                ...($request->filled('start') ? ['after_or_equal:start'] : []),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Invoice::with([
            'photographer',
            'salesRep',
            'client',
            'payments',
            'shoot',
            'shoot.client',
            'shoot.photographer',
            'shoot.payments',
            'shoots',
            'shoots.client',
            'shoots.photographer',
            'shoots.payments',
            'items',
        ])->withCount('shoots');

        // Apply role-based filtering
        if ($this->hasRole($user, self::ADMIN_ROLES)) {
            // Admins and superadmins can see all invoices
        } elseif ($user->role === 'client') {
            // Clients can see invoices for their own shoots, plus invoices from linked
            // client accounts that have shared 'invoices' with this user (owner direction only).
            $linkedInvoiceClientIds = \App\Models\AccountLink::getLinkedClientIdsForOwner(
                (int) $user->id,
                'invoices'
            );
            $clientIds = array_values(array_unique(array_merge(
                [(int) $user->id],
                array_map('intval', $linkedInvoiceClientIds)
            )));

            $query->where(function ($q) use ($clientIds) {
                $q->whereIn('client_id', $clientIds)
                    ->orWhereHas('shoots', function ($shootQuery) use ($clientIds) {
                        $shootQuery->whereIn('client_id', $clientIds);
                    });
            });
        } elseif ($user->role === 'photographer') {
            // Photographers can only see invoices for their own shoots
            $query->where(function ($q) use ($user) {
                $q->where('photographer_id', $user->id)
                    ->orWhereHas('shoots', function ($shootQuery) use ($user) {
                        $shootQuery->where('photographer_id', $user->id);
                    });
            });
        } elseif ($this->hasRole($user, self::SALES_REP_ROLES)) {
            // Sales reps can only see invoices for their clients
            $query->where(function ($q) use ($user) {
                $q->where('sales_rep_id', $user->id)
                    ->orWhereHas('shoots', function ($shootQuery) use ($user) {
                        $shootQuery->where('rep_id', $user->id);
                    })
                    ->orWhereHas('shoots.client', function ($clientQuery) use ($user) {
                        // Also check if client has this rep in metadata
                        $clientQuery->where(function ($cq) use ($user) {
                            $cq->whereRaw("JSON_EXTRACT(metadata, '$.accountRepId') = ?", [$user->id])
                                ->orWhereRaw("JSON_EXTRACT(metadata, '$.account_rep_id') = ?", [$user->id])
                                ->orWhereRaw("JSON_EXTRACT(metadata, '$.repId') = ?", [$user->id])
                                ->orWhereRaw("JSON_EXTRACT(metadata, '$.rep_id') = ?", [$user->id])
                                ->orWhere('created_by_id', $user->id);
                        });
                    });
            });
        } elseif ($user->role === 'editor') {
            return response()->json(['data' => [], 'message' => 'Editors cannot view client invoices'], 403);
        } elseif ($this->hasRole($user, ['editing_manager'])) {
            // Editing managers can see all invoices (read-only)
        } else {
            // Other roles cannot see invoices
            return response()->json(['data' => [], 'message' => 'No access to invoices'], 403);
        }

        // Additional filters (applied after role filtering)
        if ($request->filled('photographer_id')) {
            $query->where('photographer_id', $request->input('photographer_id'));
        }

        if ($request->has('paid')) {
            $query->where('is_paid', filter_var($request->input('paid'), FILTER_VALIDATE_BOOLEAN));
        }

        $start = isset($filters['start']) ? Carbon::parse($filters['start'])->startOfDay() : null;
        $end = isset($filters['end']) ? Carbon::parse($filters['end'])->endOfDay() : null;
        $this->applyInvoiceDateRange($query, $start, $end);

        $invoices = $query
            ->orderByDesc(DB::raw('COALESCE(billing_period_start, issue_date, period_start, created_at)'))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        $invoices->getCollection()->transform(
            fn (Invoice $invoice) => $invoice->applyResolvedPaymentMetadata()
        );

        return response()->json($invoices);
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        if (! $this->canViewInvoice($invoice, request()->user())) {
            abort(403, 'Forbidden');
        }

        $invoice->loadMissing([
            'client',
            'photographer',
            'salesRep',
            'items',
            'payments.refunds',
            'shoot.client',
            'shoot.payments.refunds',
            'shoots.client',
            'shoots.payments.refunds',
        ]);

        $periodStart = $invoice->billing_period_start
            ?? $invoice->period_start
            ?? $invoice->issue_date
            ?? $invoice->created_at;
        $periodEnd = $invoice->billing_period_end
            ?? $invoice->period_end
            ?? $invoice->due_date
            ?? $periodStart;
        $periodStartLabel = $periodStart?->toDateString() ?? 'Not available';
        $periodEndLabel = $periodEnd?->toDateString() ?? $periodStartLabel;
        $periodLabel = $periodStartLabel === $periodEndLabel
            ? $periodStartLabel
            : $periodStartLabel.' - '.$periodEndLabel;

        [$partyLabel, $partyName] = match ($invoice->role) {
            Invoice::ROLE_CLIENT => ['Client', $invoice->client?->name],
            Invoice::ROLE_SALES_REP => ['Sales Rep', $invoice->salesRep?->name],
            default => ['Photographer', $invoice->photographer?->name],
        };
        $partyName = $partyName ?: 'Not assigned';

        $reference = $invoice->invoice_number ?: (string) $invoice->id;
        $safeReference = Str::slug((string) $reference) ?: (string) $invoice->id;
        $startStamp = $periodStart?->format('Ymd') ?? 'undated';
        $endStamp = $periodEnd?->format('Ymd') ?? $startStamp;
        $isNoPaymentDocument = ! $invoice->requiresPayment();
        $documentLabel = $invoice->document_type === Invoice::DOCUMENT_TYPE_COMPLIMENTARY_RECEIPT
            ? 'Complimentary Receipt'
            : ($isNoPaymentDocument ? 'Receipt' : 'Invoice');

        $filename = sprintf(
            '%s-%s-%s-to-%s.csv',
            $isNoPaymentDocument ? 'receipt' : 'invoice',
            $safeReference,
            $startStamp,
            $endStamp
        );

        $shoots = $invoice->shoots;
        if ($invoice->shoot && ! $shoots->contains('id', $invoice->shoot->id)) {
            $shoots = $shoots->prepend($invoice->shoot);
        }

        $total = round((float) ($invoice->total ?? $invoice->total_amount ?? $invoice->charges_total ?? 0), 2);
        // Canonical Payment rows represent money received from a client. They
        // must not be treated as settlement of a photographer/sales-rep payout
        // merely because those invoices reference the same shoots.
        $usesClientPaymentLedger = $invoice->role === Invoice::ROLE_CLIENT;
        $hasCanonicalPayments = $usesClientPaymentLedger && $invoice->hasRelatedPaymentRecords();
        $storedPaidFlag = filter_var($invoice->getRawOriginal('is_paid'), FILTER_VALIDATE_BOOLEAN);

        if ($isNoPaymentDocument) {
            $amountPaid = 0.0;
            $balance = 0.0;
            $isPaid = false;
        } elseif ($usesClientPaymentLedger) {
            $amountPaid = $hasCanonicalPayments
                ? round($invoice->totalPaid(), 2)
                : round(max(
                    (float) ($invoice->getAttribute('amount_paid') ?? 0),
                    (float) ($invoice->getAttribute('payments_total') ?? 0)
                ), 2);
            $balance = $hasCanonicalPayments
                ? round($invoice->balanceDue(), 2)
                : round(max($total - $amountPaid, 0), 2);
            $isPaidFromBalance = $total <= 0.01
                || ($amountPaid > 0 && $balance <= 0.01);
            $isPaid = $hasCanonicalPayments
                ? $isPaidFromBalance
                : ($invoice->status === Invoice::STATUS_PAID
                    || $storedPaidFlag
                    || $isPaidFromBalance);
        } else {
            // Payout generation historically copied client receipts into
            // amount_paid/is_paid. The payout lifecycle, unlike those legacy
            // aggregates, changes status/paid_at only when the payee is paid.
            $isPaid = $invoice->status === Invoice::STATUS_PAID || $invoice->paid_at !== null;
            $amountPaid = $isPaid ? $total : 0.0;
            $balance = $isPaid ? 0.0 : round(max($total, 0), 2);
        }

        return response()->streamDownload(function () use (
            $invoice,
            $partyLabel,
            $partyName,
            $periodLabel,
            $shoots,
            $total,
            $amountPaid,
            $balance,
            $isPaid,
            $isNoPaymentDocument,
            $documentLabel
        ) {
            $handle = fopen('php://output', 'w');

            $this->writeCsvRow($handle, ['Document Type', $documentLabel]);
            $this->writeCsvRow($handle, [$documentLabel.' ID', $invoice->id]);
            $this->writeCsvRow($handle, [$documentLabel.' Number', $invoice->invoice_number ?: $invoice->id]);
            $this->writeCsvRow($handle, [$partyLabel, $partyName]);
            $this->writeCsvRow($handle, ['Billing Period', $periodLabel]);
            $this->writeCsvRow($handle, []);
            $this->writeCsvRow($handle, ['Shoot ID', 'Scheduled Date', 'Client', 'Total Quote', 'Payments Received']);

            foreach ($shoots as $shoot) {
                $paymentsReceived = $isNoPaymentDocument
                    ? 0.0
                    : $shoot->calculateCanonicalTotalPaid();

                $this->writeCsvRow($handle, [
                    $shoot->id,
                    optional($shoot->scheduled_date)->toDateString(),
                    optional($shoot->client)->name,
                    number_format((float) $shoot->total_quote, 2, '.', ''),
                    number_format((float) $paymentsReceived, 2, '.', ''),
                ]);
            }

            if ($invoice->items->isNotEmpty()) {
                $this->writeCsvRow($handle, []);
                $this->writeCsvRow($handle, [$documentLabel.' Line Items']);
                $this->writeCsvRow($handle, [
                    'Type',
                    'Description',
                    'Quantity',
                    'Unit Amount',
                    'Line Total',
                    'Shoot ID',
                ]);

                foreach ($invoice->items as $item) {
                    $this->writeCsvRow($handle, [
                        $item->type,
                        $item->description,
                        $item->quantity,
                        number_format((float) $item->unit_amount, 2, '.', ''),
                        number_format((float) $item->total_amount, 2, '.', ''),
                        $item->shoot_id,
                    ]);
                }
            }

            $this->writeCsvRow($handle, []);
            $this->writeCsvRow($handle, ['Total', number_format($total, 2, '.', '')]);
            $this->writeCsvRow($handle, ['Amount Paid', number_format($amountPaid, 2, '.', '')]);
            $this->writeCsvRow($handle, ['Balance', number_format($balance, 2, '.', '')]);
            if ($isNoPaymentDocument) {
                $this->writeCsvRow($handle, ['Payment Required', 'No']);
                $this->writeCsvRow($handle, ['Status', 'No Payment Required']);
            } else {
                $this->writeCsvRow($handle, ['Paid', $isPaid ? 'Yes' : 'No']);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Only admins, superadmins, and photographers (for their own invoices) can mark invoices as paid
        $canMarkPaid = false;
        if ($this->hasRole($user, self::ADMIN_ROLES)) {
            $canMarkPaid = true;
        } elseif ($user->role === 'photographer' && $invoice->photographer_id == $user->id) {
            $canMarkPaid = true;
        }

        if (! $canMarkPaid) {
            return response()->json(['message' => 'You do not have permission to mark this invoice as paid'], 403);
        }

        if (! $invoice->requiresPayment()) {
            return response()->json([
                'message' => 'This complimentary receipt does not require payment.',
            ], 422);
        }

        app(InvoiceAdjustmentService::class)->assertClientPaymentAllowedForInvoice($invoice);

        if ($invoice->isPayoutInvoice() && ! $invoice->isAccountsApproved()) {
            return response()->json([
                'message' => 'Accounts approval is required before a staff payout can be marked paid.',
            ], 422);
        }

        $data = $request->validate([
            'paid_at' => ['nullable', 'date'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'is_sent' => ['nullable', 'boolean'],
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
            if (! $notes) {
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
            if (! $checkNumber) {
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
        if (! $invoice->hasRelatedPaymentRecords() && $currentPaid <= 0 && $invoice->getAttribute('amount_paid') !== null) {
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

        if ($invoice->role === Invoice::ROLE_CLIENT
            && $paymentAmount > 0
            && app(InvoiceAdjustmentService::class)->relatedShoots($invoice)->count() > 1) {
            return response()->json([
                'message' => 'This invoice covers multiple shoots. Record the payment against a specific shoot so it can be allocated correctly.',
            ], 422);
        }

        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
        $amountPaid = round(
            min(
                $currentPaid + $paymentAmount,
                $invoiceTotal > 0 ? $invoiceTotal : ($currentPaid + $paymentAmount)
            ),
            2
        );
        $isNonPositivePayoutSettlement = $invoice->isPayoutInvoice() && $invoiceTotal <= 0;
        $isPaid = $invoiceTotal > 0
            ? $amountPaid >= ($invoiceTotal - 0.01)
            : $isNonPositivePayoutSettlement;
        $effectivePaidAt = $paymentAmount > 0 || $isNonPositivePayoutSettlement
            ? $paidAt
            : ($invoice->latestCompletedPayment()?->processed_at ?? $invoice->paid_at ?? now());

        $invoice->fill([
            'is_paid' => $isPaid,
            'amount_paid' => $amountPaid,
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

        if (array_key_exists('is_sent', $data)) {
            $invoice->is_sent = $data['is_sent'];
        }

        $invoice->save();
        $clientPayment = $this->syncShootPaymentFromInvoice($invoice, $paymentAmount, $paymentMethod, $paymentDetails, $paidAt);

        $invoice->loadMissing(['client', 'photographer', 'shoot', 'shoot.client']);
        if ($isPaid) {
            $this->markPayoutShootsPaid($invoice, $paidAt);
            $invoice->recordAuditEvent('paid', $request->user(), $isNonPositivePayoutSettlement
                ? 'Non-positive payout adjustment settled with no cash payment.'
                : 'Invoice payment marked as sent.', [
                'amount_paid' => $amountPaid,
                'payment_amount' => $paymentAmount,
                'payment_method' => $paymentMethod,
                'paid_at' => $paidAt->toISOString(),
                'settlement_type' => $isNonPositivePayoutSettlement ? 'non_positive_adjustment' : 'payment',
            ]);

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
        }

        // Queue the receipt from the exact Payment row so equal-value partial
        // payments cannot collide in the legacy amount lookup.
        if ($clientPayment) {
            $shootForEmail = $clientPayment->shoot ?? $invoice->shoot;
            $clientForEmail = $shootForEmail?->client ?? $invoice->client;
            if ($shootForEmail && $clientForEmail) {
                try {
                    app(MailService::class)->sendPaymentConfirmationEmail($clientForEmail, $shootForEmail, $clientPayment);
                } catch (\Throwable $emailError) {
                    Log::warning('Failed to send shoot paid email from invoice mark-paid', [
                        'invoice_id' => $invoice->id,
                        'shoot_id' => $shootForEmail->id,
                        'error' => $emailError->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'data' => $invoice
                ->fresh([
                    'photographer',
                    'salesRep',
                    'client',
                    'payments',
                    'shoot',
                    'shoot.client',
                    'shoot.photographer',
                    'shoot.payments',
                    'shoots',
                    'shoots.client',
                    'shoots.photographer',
                    'shoots.payments',
                    'items',
                ])
                ->loadCount('shoots')
                ->applyResolvedPaymentMetadata(),
        ]);
    }

    private function syncShootPaymentFromInvoice(
        Invoice $invoice,
        float $paymentAmount,
        ?string $paymentMethod,
        mixed $paymentDetails,
        Carbon $paidAt
    ): ?Payment {
        if ($paymentAmount <= 0 || $invoice->role !== Invoice::ROLE_CLIENT) {
            return null;
        }

        $invoiceAdjustments = app(InvoiceAdjustmentService::class);
        $relatedShoots = $invoiceAdjustments->relatedShoots($invoice);
        if ($relatedShoots->count() !== 1) {
            return null;
        }
        $shoot = $relatedShoots->first();

        $invoiceAdjustments->assertClientPaymentAllowedForShoot($shoot);

        $payment = Payment::create([
            'shoot_id' => $shoot->id,
            'invoice_id' => $invoice->id,
            'amount' => $paymentAmount,
            'currency' => 'USD',
            'payment_method' => $paymentMethod,
            'payment_details' => is_array($paymentDetails) ? $paymentDetails : null,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => $paidAt,
        ]);

        $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords($paymentMethod)
            ?? $shoot->syncPaymentStatusFromRecords($paymentMethod);
        $invoiceAdjustments->reconcileClientInvoicesForShoot(
            $shoot,
            $payment,
            $paymentMethod,
            $paymentDetails
        );

        return $payment->loadMissing('shoot.client');
    }

    private function markPayoutShootsPaid(Invoice $invoice, Carbon $paidAt): void
    {
        if (! in_array($invoice->role, [Invoice::ROLE_PHOTOGRAPHER, Invoice::ROLE_SALES_REP], true)) {
            return;
        }

        $invoice->loadMissing('shoots');

        foreach ($invoice->shoots as $shoot) {
            // Complimentary reshoots can contain multiple service-level
            // compensation rows with different eligibility periods. Their
            // linked invoice items are the settlement source of truth; a
            // whole-shoot paid flag would prematurely settle later rows.
            if ($shoot->isComplimentaryReshoot()) {
                continue;
            }

            $updateData = [];

            if ($invoice->photographer_id && ! $shoot->photographer_paid_at) {
                $updateData['photographer_paid_at'] = $paidAt;
                $updateData['photographer_paid_invoice_id'] = $invoice->id;
            }

            if ($invoice->sales_rep_id && ! $shoot->sales_rep_paid_at) {
                $updateData['sales_rep_paid_at'] = $paidAt;
                $updateData['sales_rep_paid_invoice_id'] = $invoice->id;
            }

            if (! empty($updateData)) {
                $shoot->update($updateData);
            }
        }
    }

    /**
     * Apply the date semantics used by the accounting UI.
     *
     * Weekly photographer and sales-rep invoices cover a period, so they match
     * when any part of that period overlaps the requested range. Client invoices
     * are point-in-time documents and match by issue date, with fallbacks for the
     * legacy invoice shapes that pre-date the issue_date column.
     */
    private function applyInvoiceDateRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if (! $start && ! $end) {
            return;
        }

        $payoutPeriodStart = DB::raw(
            'COALESCE(billing_period_start, period_start, issue_date, created_at)'
        );
        $payoutPeriodEnd = DB::raw(
            'COALESCE(billing_period_end, period_end, due_date, billing_period_start, period_start, issue_date, created_at)'
        );
        $clientIssueDate = DB::raw(
            'COALESCE(issue_date, period_start, billing_period_start, due_date, period_end, billing_period_end, created_at)'
        );

        $query->where(function (Builder $dateQuery) use (
            $start,
            $end,
            $payoutPeriodStart,
            $payoutPeriodEnd,
            $clientIssueDate
        ) {
            $dateQuery
                ->where(function (Builder $payoutQuery) use ($start, $end, $payoutPeriodStart, $payoutPeriodEnd) {
                    $payoutQuery->whereIn('role', self::PAYOUT_INVOICE_ROLES);

                    if ($start) {
                        $payoutQuery->whereDate($payoutPeriodEnd, '>=', $start->toDateString());
                    }
                    if ($end) {
                        $payoutQuery->whereDate($payoutPeriodStart, '<=', $end->toDateString());
                    }
                })
                ->orWhere(function (Builder $clientQuery) use ($start, $end, $clientIssueDate) {
                    $clientQuery->where(function (Builder $roleQuery) {
                        $roleQuery
                            ->whereNull('role')
                            ->orWhereNotIn('role', self::PAYOUT_INVOICE_ROLES);
                    });

                    if ($start) {
                        $clientQuery->whereDate($clientIssueDate, '>=', $start->toDateString());
                    }
                    if ($end) {
                        $clientQuery->whereDate($clientIssueDate, '<=', $end->toDateString());
                    }
                });
        });
    }

    /**
     * @param  resource  $handle
     */
    private function writeCsvRow($handle, array $row): void
    {
        fputcsv($handle, array_map(
            fn ($value) => $this->neutralizeSpreadsheetFormula($value),
            $row
        ));
    }

    private function neutralizeSpreadsheetFormula(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[\s\x{200B}\x{FEFF}]*[=+\-@]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    private function hasRole($user, array $allowedRoles): bool
    {
        if (! $user) {
            return false;
        }

        $normalize = static fn (?string $role): string => strtolower(str_replace(['_', '-'], '', (string) $role));
        $normalizedAllowedRoles = array_map($normalize, $allowedRoles);
        $normalizedRole = $normalize($user->role);

        if (in_array($normalizedRole, $normalizedAllowedRoles, true)) {
            return true;
        }

        $secondaryRoles = is_array($user->secondary_roles) ? $user->secondary_roles : [];

        return collect($secondaryRoles)
            ->map($normalize)
            ->intersect($normalizedAllowedRoles)
            ->isNotEmpty();
    }

    private function canViewInvoice(Invoice $invoice, $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->role === 'editor') {
            return false;
        }

        if ($this->hasRole($user, self::ADMIN_ROLES) || $this->hasRole($user, ['editing_manager'])) {
            return true;
        }

        if ($user->role === 'client') {
            $linkedInvoiceClientIds = \App\Models\AccountLink::getLinkedClientIdsForOwner(
                (int) $user->id,
                'invoices'
            );
            $clientIds = array_values(array_unique(array_merge(
                [(int) $user->id],
                array_map('intval', $linkedInvoiceClientIds)
            )));

            return in_array((int) $invoice->client_id, $clientIds, true)
                || $invoice->shoots()->whereIn('client_id', $clientIds)->exists();
        }

        if ($user->role === 'photographer') {
            return (string) $invoice->photographer_id === (string) $user->id
                || $invoice->shoots()->where('photographer_id', $user->id)->exists();
        }

        if ($this->hasRole($user, self::SALES_REP_ROLES)) {
            return (string) $invoice->sales_rep_id === (string) $user->id
                || $invoice->shoots()->where('rep_id', $user->id)->exists();
        }

        return false;
    }
}
