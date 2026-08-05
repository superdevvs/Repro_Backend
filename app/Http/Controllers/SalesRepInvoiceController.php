<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesRepInvoiceController extends Controller
{
    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];

    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Get invoices for the authenticated sales rep
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->isSalesRep($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoices = Invoice::where('sales_rep_id', $user->id)
            ->with(['items', 'shoots'])
            ->orderByDesc('billing_period_start')
            ->paginate($request->integer('per_page', 15));

        return response()->json($invoices);
    }

    /**
     * Get a specific invoice
     */
    public function show(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (!$this->isSalesRep($user) || $invoice->sales_rep_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoice->load(['items', 'shoots', 'photographer', 'salesRep']);

        return response()->json($invoice);
    }

    /**
     * Add an expense to an invoice
     */
    public function addExpense(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (!$this->isSalesRep($user) || $invoice->sales_rep_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$this->canBeModifiedBySalesRep($invoice)) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be modified in its current state.'
            ], 422);
        }

        $validated = $request->validate([
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            $item = $invoice->items()->create([
                'type' => InvoiceItem::TYPE_EXPENSE,
                'description' => $validated['description'],
                'quantity' => $validated['quantity'] ?? 1,
                'unit_amount' => $validated['amount'],
                'total_amount' => ($validated['quantity'] ?? 1) * $validated['amount'],
                'recorded_at' => now(),
            ]);

            $invoice->refreshTotals();

            if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $invoice->update([
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                ]);
            }
            $invoice->recordAuditEvent('payee_edit', $user, 'Sales rep added a commission adjustment.', [
                'item_id' => $item->id,
                'description' => $item->description,
                'amount' => (float) $item->total_amount,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Expense added successfully',
                'item' => $item,
                'invoice' => $invoice->fresh(['items']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add expense to sales rep invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to add expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove an expense from an invoice
     */
    public function removeExpense(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $user = $request->user();

        if (!$this->isSalesRep($user) || $invoice->sales_rep_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        if ($item->type !== InvoiceItem::TYPE_EXPENSE) {
            return response()->json(['message' => 'Item is not an expense'], 422);
        }

        if (!$this->canBeModifiedBySalesRep($invoice)) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be modified in its current state.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $item->delete();
            $invoice->refreshTotals();

            if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $invoice->update([
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                ]);
            }
            $invoice->recordAuditEvent('payee_edit', $user, 'Sales rep removed a commission adjustment.', [
                'item_id' => $item->id,
                'description' => $item->description,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Expense removed successfully',
                'invoice' => $invoice->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove expense from sales rep invoice', [
                'invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to remove expense',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject an invoice
     */
    public function reject(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (!$this->isSalesRep($user) || $invoice->sales_rep_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$this->canBeModifiedBySalesRep($invoice)) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be rejected in its current state.'
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_STATUS_REJECTED,
                'rejection_reason' => $validated['reason'] ?? 'Rejected by sales rep',
                'rejected_by' => $user->id,
                'rejected_at' => now(),
            ]);
            $invoice->recordAuditEvent('payee_returned', $user, 'Sales rep requested commission changes.', [
                'reason' => $validated['reason'] ?? 'Rejected by sales rep',
            ]);

            return response()->json([
                'message' => 'Invoice rejected successfully',
                'invoice' => $invoice->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reject sales rep invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to reject invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit invoice changes for approval
     */
    public function submitForApproval(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (!$this->isSalesRep($user) || $invoice->sales_rep_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$this->canBeModifiedBySalesRep($invoice)) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be submitted for approval in its current state.'
            ], 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
                'modified_by' => $user->id,
                'modified_at' => now(),
                'modification_notes' => $validated['notes'] ?? null,
            ]);
            $invoice->recordAuditEvent('submitted_for_approval', $user, 'Sales rep submitted commission invoice for accounts approval.', [
                'notes' => $validated['notes'] ?? null,
            ]);

            // Notify admins
            $this->mailService->sendInvoicePendingApprovalEmail($invoice);

            return response()->json([
                'message' => 'Invoice submitted for approval',
                'invoice' => $invoice->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to submit sales rep invoice for approval', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to submit invoice for approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if invoice can be modified by sales rep
     */
    private function canBeModifiedBySalesRep(Invoice $invoice): bool
    {
        return $invoice->canBeModifiedByPayee();
    }

    private function isSalesRep($user): bool
    {
        if (!$user) {
            return false;
        }

        $normalizedRoles = array_map('strtolower', self::SALES_REP_ROLES);
        $role = strtolower((string) $user->role);

        if (in_array($role, $normalizedRoles, true)) {
            return true;
        }

        $secondaryRoles = is_array($user->secondary_roles) ? $user->secondary_roles : [];

        return collect($secondaryRoles)
            ->map(fn ($secondaryRole) => strtolower((string) $secondaryRole))
            ->intersect($normalizedRoles)
            ->isNotEmpty();
    }
}
