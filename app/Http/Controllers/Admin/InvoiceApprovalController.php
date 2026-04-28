<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceApprovalController extends Controller
{
    protected $mailService;
    protected $invoiceService;

    public function __construct(MailService $mailService, InvoiceService $invoiceService)
    {
        $this->mailService = $mailService;
        $this->invoiceService = $invoiceService;
    }

    /**
     * Get the admin weekly-invoice review queue.
     */
    public function reviewQueue(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $filters = $request->validate([
            'role' => 'nullable|string|in:photographer,salesRep,salesrep',
            'approval_status' => 'nullable|string|in:pending_approval,approved,accounts_approved,rejected',
            'search' => 'nullable|string|max:255',
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $this->hydrateReviewQueueForWindow($filters);

        $query = $this->buildReviewQueueQuery($filters);

        $summaryQuery = clone $query;
        $statusBreakdown = (clone $summaryQuery)
            ->select('approval_status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('approval_status')
            ->pluck('aggregate', 'approval_status');

        $summary = [
            'invoice_count' => (clone $query)->count(),
            'total_amount' => round((float) (clone $query)->sum('total_amount'), 2),
            'needs_review_count' => (int) (($statusBreakdown[Invoice::APPROVAL_STATUS_PENDING_APPROVAL] ?? 0) + ($statusBreakdown[Invoice::APPROVAL_STATUS_PENDING] ?? 0)),
            'approved_count' => (int) (($statusBreakdown[Invoice::APPROVAL_STATUS_APPROVED] ?? 0) + ($statusBreakdown[Invoice::APPROVAL_STATUS_LEGACY_APPROVED] ?? 0)),
            'returned_count' => (int) ($statusBreakdown[Invoice::APPROVAL_STATUS_REJECTED] ?? 0),
        ];

        $paginator = $query->paginate($filters['per_page'] ?? 15);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Invoice $invoice) => $this->serializeReviewQueueInvoice($invoice))
                ->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'summary' => $summary,
        ]);
    }

    /**
     * Get the full admin review detail for a payout invoice.
     */
    public function reviewDetail(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($invoice->role, [
            Invoice::ROLE_PHOTOGRAPHER,
            Invoice::ROLE_SALES_REP,
        ], true)) {
            return response()->json(['message' => 'Payout invoice not found'], 404);
        }

        $invoice->load([
            'photographer',
            'salesRep',
            'items',
            'shoots.client',
            'shoots.photographer',
            'modifiedBy',
            'approvedBy',
            'rejectedBy',
            'warningOverrideBy',
            'auditEvents.actor',
        ])->loadCount([
            'shoots',
            'items as charge_count' => fn (Builder $builder) => $builder->where('type', InvoiceItem::TYPE_CHARGE),
            'items as expense_count' => fn (Builder $builder) => $builder->where('type', InvoiceItem::TYPE_EXPENSE),
        ]);

        return response()->json([
            'data' => $this->serializeReviewDetailInvoice($invoice),
        ]);
    }

    /**
     * Get invoices pending approval
     */
    public function pending(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoices = Invoice::where('approval_status', Invoice::APPROVAL_STATUS_PENDING_APPROVAL)
            ->with(['photographer', 'items', 'shoots', 'modifiedBy'])
            ->orderByDesc('modified_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($invoices);
    }

    /**
     * Approve an invoice
     */
    public function approve(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($invoice->approval_status, [
            Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            Invoice::APPROVAL_STATUS_PENDING,
        ], true)) {
            return response()->json([
                'message' => 'Invoice is not pending approval'
            ], 422);
        }

        $validated = $request->validate([
            'warning_override_reason' => 'nullable|string|max:1000',
        ]);

        $warnings = $invoice->unresolved_warnings ?? [];
        if (!empty($warnings) && empty($validated['warning_override_reason'])) {
            return response()->json([
                'message' => 'Unresolved warnings must be fixed or overridden with a reason before approval.',
                'warnings' => $warnings,
            ], 422);
        }

        try {
            DB::beginTransaction();

            $updateData = [
                'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_snapshot' => $invoice->buildApprovalSnapshot(),
            ];

            if (!empty($warnings)) {
                $updateData['warning_override_reason'] = $validated['warning_override_reason'];
                $updateData['warning_override_by'] = $user->id;
                $updateData['warning_override_at'] = now();
            }

            $invoice->update($updateData);
            $invoice->recordAuditEvent('accounts_approved', $user, 'Invoice approved by accounts.', [
                'warnings' => $warnings,
                'warning_override_reason' => $validated['warning_override_reason'] ?? null,
                'snapshot' => $invoice->approval_snapshot,
            ]);

            DB::commit();

            // Notify photographer/sales rep
            $this->mailService->sendInvoiceApprovedEmail($invoice);

            return response()->json([
                'message' => 'Invoice approved successfully',
                'invoice' => $invoice->fresh(['items', 'photographer', 'salesRep', 'shoots']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to approve invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject an invoice (admin rejection)
     */
    public function reject(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($invoice->approval_status, [
            Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
            Invoice::APPROVAL_STATUS_PENDING,
        ], true)) {
            return response()->json([
                'message' => 'Invoice is not pending approval'
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_STATUS_REJECTED,
                'rejection_reason' => $validated['reason'],
                'rejected_by' => $user->id,
                'rejected_at' => now(),
            ]);
            $invoice->recordAuditEvent('returned', $user, 'Invoice returned for changes.', [
                'reason' => $validated['reason'],
            ]);

            // Notify photographer
            $this->mailService->sendInvoiceRejectedEmail($invoice);

            return response()->json([
                'message' => 'Invoice rejected successfully',
                'invoice' => $invoice->fresh(['items', 'photographer', 'salesRep', 'shoots']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reject invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to reject invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function buildReviewQueueQuery(array $filters): Builder
    {
        $query = Invoice::query()
            ->when($this->normalizeQueueRole($filters['role'] ?? null) === 'salesRep', function (Builder $builder) {
                $builder
                    ->where('role', Invoice::ROLE_SALES_REP)
                    ->whereNotNull('sales_rep_id');
            }, function (Builder $builder) {
                $builder
                    ->where('role', Invoice::ROLE_PHOTOGRAPHER)
                    ->whereNotNull('photographer_id');
            })
            ->with([
                'photographer',
                'salesRep',
                'modifiedBy',
                'approvedBy',
                'rejectedBy',
            ])
            ->withCount([
                'shoots',
                'items as charge_count' => fn (Builder $builder) => $builder->where('type', InvoiceItem::TYPE_CHARGE),
                'items as expense_count' => fn (Builder $builder) => $builder->where('type', InvoiceItem::TYPE_EXPENSE),
            ]);

        if (!empty($filters['approval_status'])) {
            if ($filters['approval_status'] === Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $query->whereIn('approval_status', [
                    Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
                    Invoice::APPROVAL_STATUS_PENDING,
                ]);
            } elseif ($filters['approval_status'] === Invoice::APPROVAL_STATUS_LEGACY_APPROVED) {
                $query->whereIn('approval_status', [
                    Invoice::APPROVAL_STATUS_APPROVED,
                    Invoice::APPROVAL_STATUS_LEGACY_APPROVED,
                ]);
            } else {
                $query->where('approval_status', $filters['approval_status']);
            }
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $relation = $this->normalizeQueueRole($filters['role'] ?? null) === 'salesRep'
                ? 'salesRep'
                : 'photographer';

            $query->whereHas($relation, function (Builder $builder) use ($search) {
                $builder
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        if (!empty($filters['start'])) {
            $query->whereDate('billing_period_start', '>=', $filters['start']);
        }

        if (!empty($filters['end'])) {
            $query->whereDate('billing_period_end', '<=', $filters['end']);
        }

        return $query
            ->orderByRaw(
                'CASE approval_status WHEN ? THEN 0 WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 WHEN ? THEN 2 ELSE 3 END',
                [
                    Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
                    Invoice::APPROVAL_STATUS_PENDING,
                    Invoice::APPROVAL_STATUS_REJECTED,
                    Invoice::APPROVAL_STATUS_APPROVED,
                    Invoice::APPROVAL_STATUS_LEGACY_APPROVED,
                ]
            )
            ->orderByRaw('COALESCE(modified_at, approved_at, rejected_at, created_at) DESC');
    }

    private function serializeReviewQueueInvoice(Invoice $invoice): array
    {
        $role = $invoice->sales_rep_id ? 'salesRep' : 'photographer';
        $payee = $invoice->sales_rep_id ? $invoice->salesRep : $invoice->photographer;
        $lastActivityAt = $invoice->modified_at
            ?? $invoice->approved_at
            ?? $invoice->rejected_at
            ?? $invoice->created_at;

        return [
            'id' => $invoice->id,
            'role' => $role,
            'status' => $invoice->status,
            'approval_status' => $invoice->approval_status,
            'billing_period_start' => optional($invoice->billing_period_start)->toDateString(),
            'billing_period_end' => optional($invoice->billing_period_end)->toDateString(),
            'total_amount' => round((float) $invoice->total_amount, 2),
            'amount_paid' => round((float) $invoice->amount_paid, 2),
            'modification_notes' => $invoice->modification_notes,
            'rejection_reason' => $invoice->rejection_reason,
            'modified_by' => $invoice->modified_by,
            'modified_at' => optional($invoice->modified_at)->toISOString(),
            'approved_by' => $invoice->approved_by,
            'approved_at' => optional($invoice->approved_at)->toISOString(),
            'approval_snapshot' => $invoice->approval_snapshot,
            'unresolved_warnings' => $invoice->unresolved_warnings ?? [],
            'warning_override_reason' => $invoice->warning_override_reason,
            'warning_override_by' => $invoice->warning_override_by,
            'warning_override_at' => optional($invoice->warning_override_at)->toISOString(),
            'rejected_by' => $invoice->rejected_by,
            'rejected_at' => optional($invoice->rejected_at)->toISOString(),
            'created_at' => optional($invoice->created_at)->toISOString(),
            'last_activity_at' => optional($lastActivityAt)->toISOString(),
            'shoot_count' => (int) ($invoice->shoots_count ?? 0),
            'charge_count' => (int) ($invoice->charge_count ?? 0),
            'expense_count' => (int) ($invoice->expense_count ?? 0),
            'payee' => $this->serializeActor($payee),
            'photographer' => $this->serializeActor($invoice->photographer),
            'salesRep' => $this->serializeActor($invoice->salesRep),
            'modifiedBy' => $this->serializeActor($invoice->modifiedBy),
            'approvedBy' => $this->serializeActor($invoice->approvedBy),
            'rejectedBy' => $this->serializeActor($invoice->rejectedBy),
            'warningOverrideBy' => $this->serializeActor($invoice->warningOverrideBy),
        ];
    }

    private function serializeReviewDetailInvoice(Invoice $invoice): array
    {
        return array_merge($this->serializeReviewQueueInvoice($invoice), [
            'items' => $invoice->items
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'invoice_id' => $item->invoice_id,
                    'shoot_id' => $item->shoot_id,
                    'type' => $item->type,
                    'description' => $item->description,
                    'quantity' => (int) $item->quantity,
                    'unit_amount' => round((float) $item->unit_amount, 2),
                    'total_amount' => round((float) $item->total_amount, 2),
                    'recorded_at' => optional($item->recorded_at)->toISOString(),
                    'meta' => $item->meta,
                ])
                ->values(),
            'shoots' => $invoice->shoots
                ->map(fn ($shoot) => [
                    'id' => $shoot->id,
                    'address' => $shoot->address,
                    'city' => $shoot->city,
                    'state' => $shoot->state,
                    'zip' => $shoot->zip,
                    'scheduled_date' => optional($shoot->scheduled_date)->toISOString(),
                    'completed_at' => optional($shoot->completed_at)->toISOString(),
                    'total_quote' => round((float) ($shoot->total_quote ?? 0), 2),
                    'photographer_paid_at' => optional($shoot->photographer_paid_at)->toISOString(),
                    'sales_rep_paid_at' => optional($shoot->sales_rep_paid_at)->toISOString(),
                    'client' => $shoot->client ? [
                        'id' => $shoot->client->id,
                        'name' => $shoot->client->name,
                        'email' => $shoot->client->email,
                    ] : null,
                    'photographer' => $shoot->photographer ? [
                        'id' => $shoot->photographer->id,
                        'name' => $shoot->photographer->name,
                        'email' => $shoot->photographer->email,
                    ] : null,
                ])
                ->values(),
            'timeline' => $this->buildTimeline($invoice),
            'audit_events' => $invoice->auditEvents
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'event' => $event->event,
                    'summary' => $event->summary,
                    'metadata' => $event->metadata,
                    'created_at' => optional($event->created_at)->toISOString(),
                    'actor' => $this->serializeActor($event->actor),
                ])
                ->values(),
        ]);
    }

    private function buildTimeline(Invoice $invoice): array
    {
        $submittedLabel = $invoice->sales_rep_id
            ? 'Submitted for sales review'
            : 'Submitted for admin review';

        $events = collect([
            $invoice->modified_at ? [
                'key' => 'submitted',
                'label' => $submittedLabel,
                'timestamp' => $invoice->modified_at->toISOString(),
                'actor' => $this->serializeActor($invoice->modifiedBy),
            ] : null,
            $invoice->rejected_at ? [
                'key' => 'returned',
                'label' => 'Returned for changes',
                'timestamp' => $invoice->rejected_at->toISOString(),
                'actor' => $this->serializeActor($invoice->rejectedBy),
                'reason' => $invoice->rejection_reason,
            ] : null,
            $invoice->approved_at ? [
                'key' => 'approved',
                'label' => 'Approved by accounts',
                'timestamp' => $invoice->approved_at->toISOString(),
                'actor' => $this->serializeActor($invoice->approvedBy),
            ] : null,
            $invoice->warning_override_at ? [
                'key' => 'warning_override',
                'label' => 'Warnings overridden',
                'timestamp' => $invoice->warning_override_at->toISOString(),
                'actor' => $this->serializeActor($invoice->warningOverrideBy),
                'reason' => $invoice->warning_override_reason,
            ] : null,
        ])->filter();

        return $events
            ->sortByDesc('timestamp')
            ->values()
            ->all();
    }

    private function serializeActor(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }

    private function normalizeQueueRole(?string $role): string
    {
        return match ($role) {
            'salesRep', 'salesrep' => 'salesRep',
            default => 'photographer',
        };
    }

    private function hydrateReviewQueueForWindow(array $filters): void
    {
        if (!empty($filters['start']) && !empty($filters['end'])) {
            $start = Carbon::parse($filters['start'])->startOfDay();
            $end = Carbon::parse($filters['end'])->endOfDay();
        } else {
            $end = now()->startOfWeek(Carbon::SUNDAY)->subDay()->endOfDay();
            $start = $end->copy()->startOfWeek(Carbon::SUNDAY);
        }

        $this->invoiceService->generateForPeriod($start, $end, false);
        $this->invoiceService->generateSalesRepInvoicesForPeriod($start, $end, false);
    }
}


