<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Messaging\AutomationService;
use Illuminate\Http\Request;
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
            $invoice->load(['items', 'user'])
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
            'data' => $invoice,
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
        ]);

        $invoice->markPaid(isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null);

        $invoice->loadMissing(['client', 'photographer']);
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

        return response()->json([
            'message' => 'Invoice marked as paid.',
            'data' => $invoice->fresh(['items', 'user']),
        ]);
    }
}
