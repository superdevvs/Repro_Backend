<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Services\Messaging\AutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Send a payment reminder for an invoice, on demand.
 *
 * Meeting 26 Jul 2026 [00:15:28]: an overdue invoice offered no action at all.
 * The frontend had a `Send reminder` handler that only raised a toast, and no
 * endpoint existed behind it.
 *
 * This deliberately delegates to the same AutomationService the scheduled
 * reminder sweep uses, so a manual send and an automatic one deliver identical
 * messages and both leave a PaymentReminder row behind.
 */
class InvoiceReminderController extends Controller
{
    public function __construct(private AutomationService $automationService)
    {
    }

    public function send(Request $request, Invoice $invoice): JsonResponse
    {
        $shoot = $this->resolveShoot($invoice);

        if ($shoot === null) {
            return response()->json([
                'message' => 'This invoice is not linked to a shoot, so no reminder can be sent.',
            ], 422);
        }

        if ($shoot->client === null) {
            return response()->json([
                'message' => 'This shoot has no client to remind.',
            ], 422);
        }

        // Nothing owed means nothing to chase. Checking here keeps a stale list
        // in the browser from sending a client a reminder they have already paid.
        $summary = $shoot->syncPaymentStatusFromRecords();
        if (($summary['remaining_balance'] ?? 0) <= 0.01) {
            return response()->json([
                'message' => 'This shoot is fully paid — no reminder sent.',
                'payment_status' => $summary['payment_status'] ?? null,
            ], 422);
        }

        try {
            $message = $this->automationService->sendPaymentReminder($shoot);
        } catch (\Throwable $exception) {
            Log::error('Manual payment reminder failed', [
                'invoice_id' => $invoice->id,
                'shoot_id' => $shoot->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The reminder could not be sent. Please try again.',
            ], 500);
        }

        if ($message === null) {
            return response()->json([
                'message' => 'No reminder was sent — the client has no reachable email or phone on file.',
            ], 422);
        }

        // Record the manual send so the invoice has an audit trail and the
        // reminder history is complete regardless of how it was triggered.
        //
        // Keyed on (shoot_id, scheduled_date) because the table carries a unique
        // index on that pair: a plain create() threw a constraint violation the
        // second time a reminder was sent for a shoot on the same day, or when
        // the automated cadence had already scheduled one for today. Updating the
        // existing row records the send instead of failing the request.
        PaymentReminder::updateOrCreate(
            [
                'shoot_id' => $shoot->id,
                'scheduled_date' => now()->toDateString(),
            ],
            [
                'scheduled_at' => now(),
                'status' => PaymentReminder::STATUS_SENT,
                'message_id' => $message->id ?? null,
                'sent_at' => now(),
            ]
        );

        $invoice->recordAuditEvent(
            'payment_reminder_sent',
            $request->user(),
            'Payment reminder sent to the client.',
            [
                'shoot_id' => $shoot->id,
                'remaining_balance' => $summary['remaining_balance'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Payment reminder sent.',
            'shoot_id' => $shoot->id,
            'sent_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * An invoice may reference its shoot directly or through its line items.
     */
    private function resolveShoot(Invoice $invoice): ?Shoot
    {
        if ($invoice->shoot_id) {
            $shoot = Shoot::with('client')->find($invoice->shoot_id);
            if ($shoot) {
                return $shoot;
            }
        }

        $invoice->loadMissing(['shoots.client', 'items']);

        $shoot = $invoice->shoots->first();
        if ($shoot) {
            return $shoot;
        }

        $shootId = $invoice->items->pluck('shoot_id')->filter()->first();

        return $shootId ? Shoot::with('client')->find($shootId) : null;
    }
}
