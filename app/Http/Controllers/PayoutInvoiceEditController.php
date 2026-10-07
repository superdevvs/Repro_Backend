<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Invoices\PayoutInvoiceWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayoutInvoiceEditController extends Controller
{
    public function __construct(private PayoutInvoiceWorkflow $workflow) {}

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request->user(), $invoice);

        return response()->json(['invoice' => $invoice->load(['items', 'shoots', 'photographer', 'salesRep', 'auditEvents.actor'])]);
    }

    public function candidates(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request->user(), $invoice);
        abort_unless($invoice->role === Invoice::ROLE_PHOTOGRAPHER, 422, 'Shoot selection is available for photographer payouts.');
        $data = $request->validate(['search' => 'nullable|string|max:255', 'date' => 'nullable|date_format:Y-m-d']);

        return response()->json(['data' => $this->workflow->candidates($invoice, trim($data['search'] ?? ''), $data['date'] ?? null)]);
    }

    public function store(Request $request, Invoice $invoice)
    {
        $this->authorizeInvoice($request->user(), $invoice);
        $data = $request->validate([
            'action' => 'required|in:add_shoot,add_external,add_expense',
            'expected_revision' => 'required|integer|min:0',
            'shoot_id' => 'required_if:action,add_shoot|integer',
            'shoot_service_id' => 'required_if:action,add_shoot|integer',
            'description' => 'required_unless:action,add_shoot|string|max:500',
            'amount' => 'required_unless:action,add_shoot|numeric|min:0.01|max:100000',
            'quantity' => 'nullable|integer|min:1|max:1000',
            'reference' => 'required_if:action,add_external|nullable|string|max:255',
            'work_date' => 'required_if:action,add_external|nullable|date_format:Y-m-d',
            'address' => 'required_if:action,add_external|nullable|string|max:255',
            'reason' => 'required_if:action,add_external|nullable|string|max:1000',
            'verified' => 'nullable|boolean',
            'reconcile_history' => 'nullable|boolean',
        ]);
        if ($invoice->role !== Invoice::ROLE_PHOTOGRAPHER && $data['action'] !== 'add_expense') {
            throw ValidationException::withMessages(['action' => 'Use a commission adjustment for this invoice.']);
        }

        return response()->json(['invoice' => $this->workflow->mutate($invoice, $request->user(), $data)], 201);
    }

    public function update(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $this->authorizeInvoice($request->user(), $invoice);
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:0',
            'description' => 'sometimes|required|string|min:1|max:500',
            'amount' => 'sometimes|required|numeric|min:0|max:100000',
            'quantity' => 'sometimes|required|integer|min:1|max:1000',
            'reason' => 'nullable|string|max:1000', 'verified' => 'nullable|boolean',
            'reconcile_history' => 'nullable|boolean',
        ]);

        return response()->json(['invoice' => $this->workflow->mutate($invoice, $request->user(), ['action' => 'update'] + $data, $item)]);
    }

    public function destroy(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        $this->authorizeInvoice($request->user(), $invoice);
        $data = $request->validate(['expected_revision' => 'required|integer|min:0', 'reason' => 'nullable|string|max:1000', 'reconcile_history' => 'nullable|boolean']);

        return response()->json(['invoice' => $this->workflow->mutate($invoice, $request->user(), ['action' => 'remove'] + $data, $item)]);
    }

    private function authorizeInvoice(User $user, Invoice $invoice): void
    {
        $salesRoles = collect([$user->role])->merge($user->secondary_roles ?? [])->map(fn ($role) => strtolower($role));
        $owns = ($user->role === 'photographer' && $invoice->role === 'photographer' && (int) $invoice->photographer_id === (int) $user->id)
            || ($salesRoles->intersect(['salesrep', 'sales_rep'])->isNotEmpty() && $invoice->role === 'salesRep' && (int) $invoice->sales_rep_id === (int) $user->id);
        abort_unless($invoice->isPayoutInvoice() && ($owns || $this->workflow->admin($user)), 403);
    }
}
