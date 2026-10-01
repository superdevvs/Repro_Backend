<?php

namespace App\Services\Messaging;

use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Shoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Shared delivery and balance contract for payment follow-up and overdue requests. */
class ShootPaymentReminderEligibility
{
    public function completionAnchor(Shoot $shoot): ?CarbonImmutable
    {
        if ($shoot->workflow_status !== Shoot::STATUS_DELIVERED || $shoot->delivery_status !== 'delivered'
            || in_array(strtolower((string) $shoot->status), ['cancelled', 'canceled', 'declined', 'hold_on', 'on_hold'], true)) {
            return null;
        }

        // Some explicitly delivered legacy records lack completed_at. A successful
        // ready notice is evidence of delivery; the booking date never is.
        $anchor = $shoot->completed_at ?? $shoot->shoot_ready_notified_at;

        return $anchor ? CarbonImmutable::instance($anchor)->utc() : null;
    }

    public function reminderAnchor(Shoot $shoot): ?CarbonImmutable
    {
        $completed = $this->completionAnchor($shoot);
        if (! $completed || ! $shoot->shoot_ready_notified_at) {
            return null;
        }
        $notified = CarbonImmutable::instance($shoot->shoot_ready_notified_at)->utc();

        return $completed->greaterThan($notified) ? $completed : $notified;
    }

    public function balanceDue(Shoot $shoot): float
    {
        $shoot->loadMissing('payments.refunds');

        return round(max((float) $shoot->total_quote - $shoot->calculateCanonicalTotalPaid(), 0), 2);
    }

    public function isEligible(Shoot $shoot): bool
    {
        return $this->reminderAnchor($shoot) !== null
            && ! $shoot->suppressesExternalNotifications()
            && ! $shoot->bypass_paywall
            && ! in_array(strtolower((string) $shoot->payment_status), ['paid', Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED], true)
            && $shoot->client !== null
            && $this->balanceDue($shoot) > 0.01;
    }

    /** Shoot balances have exactly one reminder pipeline, independent of invoice due dates. */
    public function invoiceHasShoot(Invoice $invoice): bool
    {
        return $invoice->shoot_id !== null
            || DB::table('invoice_shoot')->where('invoice_id', $invoice->id)->exists()
            || $invoice->items()->whereNotNull('shoot_id')->exists();
    }

    /** Persisted retries must not revive a superseded payment request. */
    public function storedReminderIsStale(Message $message): bool
    {
        if ($message->send_source !== 'AUTOMATION') {
            return false;
        }
        $tags = collect((array) $message->tags_json)->filter(fn ($tag) => is_string($tag));
        if ($tags->contains(fn ($tag) => str_starts_with($tag, 'INVOICE_DUE:') || str_starts_with($tag, 'INVOICE_OVERDUE:'))) {
            $invoice = Invoice::find($message->related_invoice_id);

            return ! $invoice || $this->invoiceHasShoot($invoice) || $invoice->suppressesExternalNotifications()
                || ! $invoice->client_id || (int) $message->related_account_id !== (int) $invoice->client_id
                || in_array(strtolower((string) $invoice->status), ['paid', 'cancelled', 'canceled', 'void'], true)
                || $invoice->balanceDue() <= 0;
        }
        if (! $tags->contains(fn ($tag) => str_starts_with($tag, 'PAYMENT_REMINDER:shoot:'))) {
            return false;
        }
        if (AutomationRule::forTrigger('SHOOT_PAYMENT_REMINDER')->exists()
            && ! AutomationRule::active()->forTrigger('SHOOT_PAYMENT_REMINDER')->exists()) {
            return true;
        }
        $shoot = Shoot::find($message->related_shoot_id);
        if (! $shoot || ! $this->isEligible($shoot) || (int) $message->related_account_id !== (int) $shoot->client_id
            || $this->reminderAnchor($shoot)->addDay()->isFuture()) {
            return true;
        }
        // A saved body contains its original balance and address. If the shoot
        // changed after composition, the next cadence builds a fresh message.
        return $shoot->updated_at && $message->created_at && $shoot->updated_at->greaterThan($message->created_at);
    }
}
