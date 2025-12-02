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
                'message' => 'Invoice cannot be modified in its current state'
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
                'message' => 'Invoice cannot be modified in its current state'
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
     * Reject an invoice
     */
    public function reject(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if ($user->role !== 'photographer' || $invoice->photographer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$invoice->canBeModifiedByPhotographer()) {
            return response()->json([
                'message' => 'Invoice cannot be rejected in its current state'
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_STATUS_REJECTED,
                'rejection_reason' => $validated['reason'] ?? 'Rejected by photographer',
                'rejected_by' => $user->id,
                'rejected_at' => now(),
            ]);

            return response()->json([
                'message' => 'Invoice rejected successfully',
                'invoice' => $invoice->fresh(),
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
                'message' => 'Invoice cannot be submitted for approval in its current state'
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

            // Notify admins
            $this->mailService->sendInvoicePendingApprovalEmail($invoice);

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


