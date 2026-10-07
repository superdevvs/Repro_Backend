<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\MailService;
use Illuminate\Http\Request;

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

        if (! $this->isSalesRep($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoices = Invoice::where('sales_rep_id', $user->id)
            ->where('role', Invoice::ROLE_SALES_REP)
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

        if (! $this->ownsSalesRepPayoutInvoice($user, $invoice)) {
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
        if (! $this->ownsSalesRepPayoutInvoice($user, $invoice)) {
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
        if (! $this->ownsSalesRepPayoutInvoice($user, $invoice)) {
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
     * Legacy "reject with changes" action. Changed commission invoices belong
     * in the admin review queue; only an admin return should use rejected state.
     */
    public function reject(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if (! $this->ownsSalesRepPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->submit($invoice, $user, $data['reason'] ?? null, true);

        return response()->json(['message' => 'Invoice submitted for accounts review', 'invoice' => $result]);
    }

    /**
     * Submit invoice changes for approval
     */
    public function submitForApproval(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if (! $this->ownsSalesRepPayoutInvoice($user, $invoice)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate(['notes' => 'nullable|string|max:1000', 'expected_revision' => 'nullable|integer|min:0']);
        $result = app(\App\Services\Invoices\PayoutInvoiceWorkflow::class)->submit($invoice, $user, $data['notes'] ?? null, false, $data['expected_revision'] ?? null);

        return response()->json(['message' => 'Invoice submitted for accounts review', 'invoice' => $result]);
    }

    /**
     * Check if invoice can be modified by sales rep
     */
    private function canBeModifiedBySalesRep(Invoice $invoice): bool
    {
        return $invoice->canBeModifiedByPayee();
    }

    /**
     * Client invoices may carry a rep id for attribution and must not become
     * editable through the sales-rep payout workflow.
     */
    private function ownsSalesRepPayoutInvoice($user, Invoice $invoice): bool
    {
        return $this->isSalesRep($user)
            && $invoice->role === Invoice::ROLE_SALES_REP
            && (int) $invoice->sales_rep_id === (int) $user->id;
    }

    private function isSalesRep($user): bool
    {
        if (! $user) {
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
