<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Messaging\AutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            $invoice
                ->load([
                    'items',
                    'user',
                    'client',
                    'photographer',
                    'payments',
                    'shoot',
                    'shoot.client',
                    'shoot.photographer',
                    'shoot.payments',
                    'shoots',
                    'shoots.client',
                    'shoots.photographer',
                    'shoots.payments',
                ])
                ->applyResolvedPaymentMetadata()
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
            if (!$notes) {
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
            if (!$checkNumber) {
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

        $invoice->markPaid(isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null);

        if ($paymentMethod !== null) {
            $invoice->payment_method = $paymentMethod;
            $invoice->payment_details = $paymentDetails;
            $invoice->save();
        }

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

    public function addMiscItem(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            DB::beginTransaction();

            $item = $invoice->items()->create([
                'type' => InvoiceItem::TYPE_EXPENSE,
                'description' => $data['description'],
                'quantity' => $data['quantity'] ?? 1,
                'unit_amount' => $data['amount'],
                'total_amount' => ($data['quantity'] ?? 1) * $data['amount'],
                'recorded_at' => now(),
                'meta' => [
                    'source' => 'admin_misc',
                ],
            ]);

            $invoice->refreshTotals();
            $invoice->update([
                'modified_by' => $request->user()?->id,
                'modified_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Misc item added successfully',
                'item' => $item,
                'invoice' => $invoice->fresh(['items', 'user']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add misc item to invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to add misc item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function removeMiscItem(Request $request, Invoice $invoice, InvoiceItem $item)
    {
        if ($item->invoice_id !== $invoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        $source = is_array($item->meta) ? ($item->meta['source'] ?? null) : null;
        if ($item->type !== InvoiceItem::TYPE_EXPENSE || $source !== 'admin_misc') {
            return response()->json(['message' => 'Item is not an admin misc item'], 422);
        }

        try {
            DB::beginTransaction();

            $item->delete();
            $invoice->refreshTotals();
            $invoice->update([
                'modified_by' => $request->user()?->id,
                'modified_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Misc item removed successfully',
                'invoice' => $invoice->fresh(['items', 'user']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove misc item from invoice', [
                'invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to remove misc item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
