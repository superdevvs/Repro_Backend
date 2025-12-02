<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceApprovalController extends Controller
{
    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Get invoices pending approval
     */
    public function pending(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'super_admin'])) {
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

        if (!in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
            return response()->json([
                'message' => 'Invoice is not pending approval'
            ], 422);
        }

        try {
            $invoice->update([
                'approval_status' => Invoice::APPROVAL_STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            // Notify photographer
            $this->mailService->sendInvoiceApprovedEmail($invoice);

            return response()->json([
                'message' => 'Invoice approved successfully',
                'invoice' => $invoice->fresh(['items', 'photographer']),
            ]);
        } catch (\Exception $e) {
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

        if (!in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($invoice->approval_status !== Invoice::APPROVAL_STATUS_PENDING_APPROVAL) {
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

            // Notify photographer
            $this->mailService->sendInvoiceRejectedEmail($invoice);

            return response()->json([
                'message' => 'Invoice rejected successfully',
                'invoice' => $invoice->fresh(['items', 'photographer']),
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
}


