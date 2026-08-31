<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PhotographerInvoiceController extends Controller
{
    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Get invoices for the authenticated photographer
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'photographer') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoices = Invoice::where('photographer_id', $user->id)
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

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
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

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
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

            // Mark as modified if not already pending approval
            if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $invoice->update([
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                ]);
            }
            $invoice->recordAuditEvent('payee_edit', $user, 'Photographer added an invoice expense.', [
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
            Log::error('Failed to add expense to invoice', [
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

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        if ($item->type !== InvoiceItem::TYPE_EXPENSE) {
            return response()->json(['message' => 'Item is not an expense'], 422);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be modified in its current state.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $item->delete();
            $invoice->refreshTotals();

            // Mark as modified if not already pending approval
            if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $invoice->update([
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                ]);
            }
            $invoice->recordAuditEvent('payee_edit', $user, 'Photographer removed an invoice expense.', [
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
            Log::error('Failed to remove expense from invoice', [
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
     * Legacy "reject with changes" action.
     *
     * A photographer's changed invoice is work for accounts to review, not an
     * invoice returned to the photographer. Keep this route for older clients,
     * but move the invoice directly into the admin review queue.
     */
    public function reject(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be rejected in its current state.'
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $changeSummary = $validated['reason'] ?? 'Photographer submitted invoice changes.';

            DB::transaction(function () use ($invoice, $user, $changeSummary) {
                $invoice->update([
                    'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                    'modification_notes' => $changeSummary,
                    'rejection_reason' => null,
                    'rejected_by' => null,
                    'rejected_at' => null,
                ]);
                $invoice->recordAuditEvent(
                    'submitted_with_changes',
                    $user,
                    'Photographer submitted changed invoice for admin review.',
                    ['notes' => $changeSummary]
                );
            });

            $this->mailService->sendInvoicePendingApprovalEmail($invoice->fresh());

            return response()->json([
                'message' => 'Invoice changes submitted for admin review',
                'invoice' => $invoice->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to submit changed photographer invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to submit invoice changes for review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an item (description / amount / quantity) on a photographer invoice.
     * Allowed for both charge and expense items while the invoice is photographer-editable.
     */
    public function updateItem(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $user = $request->user();

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be modified in its current state.'
            ], 422);
        }

        $validated = $request->validate([
            'description' => 'sometimes|nullable|string|max:500',
            'amount' => 'sometimes|nullable|numeric|min:0',
            'quantity' => 'sometimes|nullable|integer|min:1',
        ]);

        try {
            DB::beginTransaction();

            $updates = [];
            if (array_key_exists('description', $validated) && $validated['description'] !== null) {
                $updates['description'] = $validated['description'];
            }

            $unitAmount = array_key_exists('amount', $validated) && $validated['amount'] !== null
                ? (float) $validated['amount']
                : (float) $item->unit_amount;
            $quantity = array_key_exists('quantity', $validated) && $validated['quantity'] !== null
                ? (int) $validated['quantity']
                : (int) ($item->quantity ?: 1);

            if (array_key_exists('amount', $validated) && $validated['amount'] !== null) {
                $updates['unit_amount'] = $unitAmount;
            }
            if (array_key_exists('quantity', $validated) && $validated['quantity'] !== null) {
                $updates['quantity'] = $quantity;
            }
            if (isset($updates['unit_amount']) || isset($updates['quantity'])) {
                $updates['total_amount'] = $unitAmount * $quantity;
            }

            if (!empty($updates)) {
                $item->update($updates);
                $invoice->refreshTotals();
            }

            if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $invoice->update([
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                ]);
            }
            $invoice->recordAuditEvent('payee_edit', $user, 'Photographer updated an invoice line.', [
                'item_id' => $item->id,
                'changes' => $updates,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Item updated successfully',
                'item' => $item->fresh(),
                'invoice' => $invoice->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update invoice item', [
                'invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to update item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a new charge (service line) to a photographer invoice.
     */
    public function addCharge(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be modified in its current state.'
            ], 422);
        }

        $validated = $request->validate([
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
            'shoot_id' => 'nullable|integer|exists:shoots,id',
        ]);

        try {
            DB::beginTransaction();

            $quantity = $validated['quantity'] ?? 1;
            $item = $invoice->items()->create([
                'type' => InvoiceItem::TYPE_CHARGE,
                'description' => $validated['description'],
                'quantity' => $quantity,
                'unit_amount' => $validated['amount'],
                'total_amount' => $quantity * $validated['amount'],
                'shoot_id' => $validated['shoot_id'] ?? null,
                'recorded_at' => now(),
                'meta' => ['source' => 'photographer_added'],
            ]);

            $invoice->refreshTotals();

            if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $invoice->update([
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                ]);
            }
            $invoice->recordAuditEvent('payee_edit', $user, 'Photographer added a service line.', [
                'item_id' => $item->id,
                'description' => $item->description,
                'amount' => (float) $item->total_amount,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Service added successfully',
                'item' => $item,
                'invoice' => $invoice->fresh(['items']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add charge to invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to add service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a charge (service line) from a photographer invoice.
     */
    public function removeCharge(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $user = $request->user();

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        if ($item->type !== InvoiceItem::TYPE_CHARGE) {
            return response()->json(['message' => 'Item is not a service charge'], 422);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be modified in its current state.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $itemSnapshot = [
                'item_id' => $item->id,
                'description' => $item->description,
                'amount' => (float) $item->total_amount,
            ];

            $item->delete();
            $invoice->refreshTotals();

            if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
                $invoice->update([
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                ]);
            }
            $invoice->recordAuditEvent('payee_edit', $user, 'Photographer removed a service line.', $itemSnapshot);

            DB::commit();

            return response()->json([
                'message' => 'Service removed successfully',
                'invoice' => $invoice->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove charge from invoice', [
                'invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to remove service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit invoice changes for approval
     */
    public function submitForApproval(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
            return response()->json([
                'message' => $invoice->editLockedReason() ?? 'Invoice cannot be submitted for approval in its current state.'
            ], 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::transaction(function () use ($invoice, $user, $validated) {
                $invoice->update([
                    'approval_status' => Invoice::APPROVAL_STATUS_PENDING_APPROVAL,
                    'modified_by' => $user->id,
                    'modified_at' => now(),
                    'modification_notes' => $validated['notes'] ?? null,
                    'rejection_reason' => null,
                    'rejected_by' => null,
                    'rejected_at' => null,
                ]);
                $invoice->recordAuditEvent('submitted_for_approval', $user, 'Photographer submitted invoice for accounts approval.', [
                    'notes' => $validated['notes'] ?? null,
                ]);
            });

            // Notify admins
            $this->mailService->sendInvoicePendingApprovalEmail($invoice->fresh());

            return response()->json([
                'message' => 'Invoice submitted for approval',
                'invoice' => $invoice->fresh(['items']),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to submit invoice for approval', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to submit invoice for approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}


