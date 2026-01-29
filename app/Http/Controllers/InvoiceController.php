<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Messaging\AutomationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = Invoice::with([
            'photographer',
            'salesRep',
            'client',
            'shoot',
            'shoot.client',
            'shoot.photographer',
            'shoots',
            'shoots.client',
            'shoots.photographer',
            'items',
        ])->withCount('shoots');

        // Apply role-based filtering
        if (in_array($user->role, ['admin', 'superadmin'])) {
            // Admins and superadmins can see all invoices
        } elseif ($user->role === 'client') {
            // Clients can only see invoices for their own shoots
            $query->where(function ($q) use ($user) {
                $q->where('client_id', $user->id)
                  ->orWhereHas('shoots', function ($shootQuery) use ($user) {
                      $shootQuery->where('client_id', $user->id);
                  });
            });
        } elseif ($user->role === 'photographer') {
            // Photographers can only see invoices for their own shoots
            $query->where(function ($q) use ($user) {
                $q->where('photographer_id', $user->id)
                  ->orWhereHas('shoots', function ($shootQuery) use ($user) {
                      $shootQuery->where('photographer_id', $user->id);
                  });
            });
        } elseif ($user->role === 'salesRep') {
            // Sales reps can only see invoices for their clients
            $query->where(function ($q) use ($user) {
                $q->where('sales_rep_id', $user->id)
                  ->orWhereHas('shoots', function ($shootQuery) use ($user) {
                      $shootQuery->where('rep_id', $user->id);
                  })
                  ->orWhereHas('shoots.client', function ($clientQuery) use ($user) {
                      // Also check if client has this rep in metadata
                      $clientQuery->where(function ($cq) use ($user) {
                          $cq->whereRaw("JSON_EXTRACT(metadata, '$.accountRepId') = ?", [$user->id])
                             ->orWhereRaw("JSON_EXTRACT(metadata, '$.account_rep_id') = ?", [$user->id])
                             ->orWhereRaw("JSON_EXTRACT(metadata, '$.repId') = ?", [$user->id])
                             ->orWhereRaw("JSON_EXTRACT(metadata, '$.rep_id') = ?", [$user->id])
                             ->orWhere('created_by_id', $user->id);
                      });
                  });
            });
        } else {
            // Other roles (editor, etc.) cannot see invoices
            return response()->json(['data' => [], 'message' => 'No access to invoices'], 403);
        }

        // Additional filters (applied after role filtering)
        if ($request->filled('photographer_id')) {
            $query->where('photographer_id', $request->input('photographer_id'));
        }

        if ($request->has('paid')) {
            $query->where('is_paid', filter_var($request->input('paid'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('start')) {
            $start = Carbon::parse($request->input('start'))->startOfDay();
            $query->whereDate('billing_period_start', '>=', $start);
        }

        if ($request->filled('end')) {
            $end = Carbon::parse($request->input('end'))->endOfDay();
            $query->whereDate('billing_period_end', '<=', $end);
        }

        $invoices = $query
            ->orderByDesc('billing_period_start')
            ->paginate($request->integer('per_page', 15));

        return response()->json($invoices);
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        $invoice->loadMissing(['photographer', 'salesRep', 'shoots.client', 'shoots.payments']);

        $filename = sprintf(
            'invoice-%s-%s-%s.csv',
            $invoice->photographer?->username ?? 'photographer',
            $invoice->billing_period_start->format('Ymd'),
            $invoice->billing_period_end->format('Ymd')
        );

        return response()->streamDownload(function () use ($invoice) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Invoice ID', $invoice->id]);
            fputcsv($handle, ['Photographer', optional($invoice->photographer)->name]);
            fputcsv($handle, ['Billing Period', $invoice->billing_period_start->toDateString() . ' - ' . $invoice->billing_period_end->toDateString()]);
            fputcsv($handle, []);
            fputcsv($handle, ['Shoot ID', 'Scheduled Date', 'Client', 'Total Quote', 'Payments Received']);

            foreach ($invoice->shoots as $shoot) {
                $paymentsReceived = $shoot->payments
                    ->where('status', Payment::STATUS_COMPLETED)
                    ->sum('amount');

                fputcsv($handle, [
                    $shoot->id,
                    optional($shoot->scheduled_date)->toDateString(),
                    optional($shoot->client)->name,
                    number_format((float) $shoot->total_quote, 2, '.', ''),
                    number_format((float) $paymentsReceived, 2, '.', ''),
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['Total', number_format((float) $invoice->total_amount, 2, '.', '')]);
            fputcsv($handle, ['Amount Paid', number_format((float) $invoice->amount_paid, 2, '.', '')]);
            fputcsv($handle, ['Paid', $invoice->is_paid ? 'Yes' : 'No']);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Only admins, superadmins, and photographers (for their own invoices) can mark invoices as paid
        $canMarkPaid = false;
        if (in_array($user->role, ['admin', 'superadmin'])) {
            $canMarkPaid = true;
        } elseif ($user->role === 'photographer' && $invoice->photographer_id == $user->id) {
            $canMarkPaid = true;
        }

        if (!$canMarkPaid) {
            return response()->json(['message' => 'You do not have permission to mark this invoice as paid'], 403);
        }

        $data = $request->validate([
            'paid_at' => ['nullable', 'date'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'is_sent' => ['nullable', 'boolean'],
        ]);

        $invoice->fill([
            'is_paid' => true,
            'amount_paid' => $data['amount_paid'] ?? $invoice->total_amount,
            'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
        ]);

        if (array_key_exists('is_sent', $data)) {
            $invoice->is_sent = $data['is_sent'];
        }

        $invoice->save();

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
            'data' => $invoice->fresh(['photographer', 'salesRep'])->loadCount('shoots'),
        ]);
    }
}
