<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\MailService;
use Illuminate\Http\Request;

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
            ->where('role', Invoice::ROLE_PHOTOGRAPHER)
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

        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
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
        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate([
            'description' => 'required|string|max:500', 'amount' => 'required|numeric|min:0|max:100000',
            'quantity' => 'nullable|integer|min:1|max:1000', 'shoot_id' => 'prohibited',
        ]);
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->mutate($invoice, $user, ['action' => 'add_expense'] + $data);

        return response()->json(['message' => 'Invoice updated', 'invoice' => $result, 'item' => $result->items->last()], 201);
    }

    /**
     * Remove an expense from an invoice
     */
    public function removeExpense(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $user = $request->user();
        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ((int) $item->invoice_id !== (int) $invoice->id || $item->type !== InvoiceItem::TYPE_EXPENSE) {
            return response()->json(['message' => 'Item does not belong to this invoice or has the wrong type'], 422);
        }
        $data = [];
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->mutate($invoice, $user, ['action' => 'remove'] + $data, $item);

        return response()->json(['message' => 'Invoice updated', 'invoice' => $result], 200);
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
        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->submit($invoice, $user, $data['reason'] ?? null, true);

        return response()->json(['message' => 'Invoice submitted for accounts review', 'invoice' => $result]);
    }

    /**
     * Update an item (description / amount / quantity) on a photographer invoice.
     * Allowed for both charge and expense items while the invoice is photographer-editable.
     */
    public function updateItem(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $user = $request->user();
        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ((int) $item->invoice_id !== (int) $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice or has the wrong type'], 422);
        }
        $data = $request->validate([
            'description' => 'sometimes|string|max:500', 'amount' => 'sometimes|numeric|min:0|max:100000',
            'quantity' => 'nullable|integer|min:1|max:1000', 'shoot_id' => 'prohibited',
        ]);
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->mutate($invoice, $user, ['action' => 'update'] + $data, $item);

        return response()->json(['message' => 'Invoice updated', 'invoice' => $result], 200);
    }

    /**
     * Add a new charge (service line) to a photographer invoice.
     */
    public function addCharge(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate([
            'description' => 'required|string|max:500', 'amount' => 'required|numeric|min:0|max:100000',
            'quantity' => 'nullable|integer|min:1|max:1000', 'shoot_id' => 'prohibited',
        ]);
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->mutate($invoice, $user, ['action' => 'add_charge'] + $data);

        return response()->json(['message' => 'Invoice updated', 'invoice' => $result, 'item' => $result->items->last()], 201);
    }

    /**
     * Remove a charge (service line) from a photographer invoice.
     */
    public function removeCharge(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $user = $request->user();
        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ((int) $item->invoice_id !== (int) $invoice->id || $item->type !== InvoiceItem::TYPE_CHARGE) {
            return response()->json(['message' => 'Item does not belong to this invoice or has the wrong type'], 422);
        }
        $data = [];
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->mutate($invoice, $user, ['action' => 'remove'] + $data, $item);

        return response()->json(['message' => 'Invoice updated', 'invoice' => $result], 200);
    }

    /**
     * Submit invoice changes for approval
     */
    public function submitForApproval(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if (! $this->ownsPhotographerPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate(['notes' => 'nullable|string|max:1000', 'expected_revision' => 'nullable|integer|min:0']);
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->submit($invoice, $user, $data['notes'] ?? null, false, $data['expected_revision'] ?? null);

        return response()->json(['message' => 'Invoice submitted for accounts review', 'invoice' => $result]);
    }

    /**
     * Client invoices carry photographer_id for shoot attribution, so the
     * recipient id alone must never grant access to a client's receivable.
     */
    private function ownsPhotographerPayoutInvoice(?\App\Models\User $user, Invoice $invoice): bool
    {
        return $user?->role === Invoice::ROLE_PHOTOGRAPHER
            && $invoice->role === Invoice::ROLE_PHOTOGRAPHER
            && (int) $invoice->photographer_id === (int) $user->id;
    }
}
