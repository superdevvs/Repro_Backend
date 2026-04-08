<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    private const ADMIN_ROLES = ['admin', 'superadmin', 'super_admin', 'editing_manager'];
    private const SALES_REP_ROLES = ['salesRep', 'sales_rep', 'salesrep'];

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
            'payments',
            'shoot',
            'shoot.client',
            'shoot.photographer',
            'shoot.payments',
            'shoots',
            'shoots.client',
            'shoots.photographer',
            'shoots.payments',
            'items',
        ])->withCount('shoots');

        // Apply role-based filtering
        if ($this->hasRole($user, self::ADMIN_ROLES)) {
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
        } elseif ($this->hasRole($user, self::SALES_REP_ROLES)) {
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
        } elseif ($user->role === 'editor') {
            return response()->json(['data' => [], 'message' => 'Editors cannot view client invoices'], 403);
        } elseif ($this->hasRole($user, ['editing_manager'])) {
            // Editing managers can see all invoices (read-only)
        } else {
            // Other roles cannot see invoices
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

        $invoices->getCollection()->transform(
            fn (Invoice $invoice) => $invoice->applyResolvedPaymentMetadata()
        );

        return response()->json($invoices);
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        if (!$this->canViewInvoice($invoice, request()->user())) {
            abort(403, 'Forbidden');
        }

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
        if ($this->hasRole($user, self::ADMIN_ROLES)) {
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

        $invoiceTotal = (float) ($invoice->total ?? $invoice->total_amount ?? 0);
        $amountPaid = round((float) ($data['amount_paid'] ?? $invoiceTotal), 2);
        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
        $isPaid = $invoiceTotal > 0
            ? $amountPaid >= ($invoiceTotal - 0.01)
            : $amountPaid > 0;

        $invoice->fill([
            'is_paid' => $isPaid,
            'amount_paid' => $amountPaid,
            'paid_at' => $isPaid ? $paidAt : null,
            'status' => $isPaid
                ? Invoice::STATUS_PAID
                : (($invoice->status ?? Invoice::STATUS_SENT) === Invoice::STATUS_DRAFT
                    ? Invoice::STATUS_SENT
                    : ($invoice->status ?? Invoice::STATUS_SENT)),
        ]);

        if ($paymentMethod !== null) {
            $invoice->payment_method = $paymentMethod;
            $invoice->payment_details = $paymentDetails;
        }

        if (array_key_exists('is_sent', $data)) {
            $invoice->is_sent = $data['is_sent'];
        }

        $invoice->save();
        $this->syncShootPaymentFromInvoice($invoice, $amountPaid, $paymentMethod, $paymentDetails, $paidAt);

        $invoice->loadMissing(['client', 'photographer']);
        if ($isPaid) {
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
        }

        return response()->json([
            'data' => $invoice->fresh(['photographer', 'salesRep'])->loadCount('shoots'),
        ]);
    }

    private function syncShootPaymentFromInvoice(
        Invoice $invoice,
        float $amountPaid,
        ?string $paymentMethod,
        mixed $paymentDetails,
        Carbon $paidAt
    ): void {
        if ($amountPaid <= 0) {
            return;
        }

        $shoot = $invoice->shoot ?: ($invoice->shoot_id ? Shoot::find($invoice->shoot_id) : null);
        if (!$shoot) {
            return;
        }

        $payment = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('shoot_id', $shoot->id)
            ->where('status', Payment::STATUS_COMPLETED)
            ->first();

        if ($payment) {
            $payment->fill([
                'amount' => $amountPaid,
                'payment_method' => $paymentMethod,
                'payment_details' => is_array($paymentDetails) ? $paymentDetails : null,
                'processed_at' => $paidAt,
            ]);
            $payment->save();
        } else {
            Payment::create([
                'shoot_id' => $shoot->id,
                'invoice_id' => $invoice->id,
                'amount' => $amountPaid,
                'currency' => 'USD',
                'payment_method' => $paymentMethod,
                'payment_details' => is_array($paymentDetails) ? $paymentDetails : null,
                'status' => Payment::STATUS_COMPLETED,
                'processed_at' => $paidAt,
            ]);
        }

        $shoot->fresh(['payments'])?->syncPaymentStatusFromRecords($paymentMethod)
            ?? $shoot->syncPaymentStatusFromRecords($paymentMethod);
    }

    private function hasRole($user, array $allowedRoles): bool
    {
        if (!$user) {
            return false;
        }

        $normalize = static fn (?string $role): string => strtolower(str_replace(['_', '-'], '', (string) $role));
        $normalizedAllowedRoles = array_map($normalize, $allowedRoles);
        $normalizedRole = $normalize($user->role);

        if (in_array($normalizedRole, $normalizedAllowedRoles, true)) {
            return true;
        }

        $secondaryRoles = is_array($user->secondary_roles) ? $user->secondary_roles : [];

        return collect($secondaryRoles)
            ->map($normalize)
            ->intersect($normalizedAllowedRoles)
            ->isNotEmpty();
    }

    private function canViewInvoice(Invoice $invoice, $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->role === 'editor') {
            return false;
        }

        if ($this->hasRole($user, self::ADMIN_ROLES) || $this->hasRole($user, ['editing_manager'])) {
            return true;
        }

        if ($user->role === 'client') {
            return (string) $invoice->client_id === (string) $user->id
                || $invoice->shoots()->where('client_id', $user->id)->exists();
        }

        if ($user->role === 'photographer') {
            return (string) $invoice->photographer_id === (string) $user->id
                || $invoice->shoots()->where('photographer_id', $user->id)->exists();
        }

        if ($this->hasRole($user, self::SALES_REP_ROLES)) {
            return (string) $invoice->sales_rep_id === (string) $user->id
                || $invoice->shoots()->where('rep_id', $user->id)->exists();
        }

        return false;
    }
}
