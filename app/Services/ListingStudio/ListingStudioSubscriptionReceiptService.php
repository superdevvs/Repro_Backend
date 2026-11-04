<?php

namespace App\Services\ListingStudio;

use App\Jobs\SendListingStudioSubscriptionReceipt;
use App\Models\ListingStudioSubscription;
use App\Models\ListingStudioSubscriptionReceipt as Receipt;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ListingStudioSubscriptionReceiptService
{
    /** Called with credit reconciliation inside the same short transaction. No external work. */
    public function reserve(ListingStudioSubscription $subscription, array $invoices, array $knownInvoices): void
    {
        if (! $subscription->client_id || $subscription->sync_status !== 'synced' || $subscription->status !== 'active') {
            return;
        }
        $client = User::find($subscription->client_id);
        if (! $client || $client->role !== 'client' || ! $client->isAccountEligibleForAuthentication()) {
            return;
        }
        foreach ($invoices as $invoice) {
            $invoiceId = $invoice['id'] ?? '';
            if (($invoice['billing_reason'] ?? '') !== 'subscription_create' || ($invoice['status'] ?? '') !== 'paid'
                || in_array($invoiceId, $knownInvoices, true) || $invoiceId !== $subscription->latest_invoice_id) {
                continue;
            }
            // The grant, applied plan, period and receipt must all describe the same verified benefit.
            $granted = (int) DB::table('listing_studio_credit_entries')->where('subscription_id', $subscription->id)
                ->where('source_type', 'invoice')->where('stripe_invoice_id', $invoiceId)->where('plan_code', $subscription->plan_code)
                ->where('period_start', $subscription->current_period_start)->where('expires_at', $subscription->current_period_end)->sum('amount_cents');
            if ($granted <= 0 || ! $subscription->current_period_end?->isFuture() || $this->refunded($subscription, $invoiceId)) {
                continue;
            }
            Receipt::firstOrCreate(['subscription_id' => $subscription->id, 'invoice_id' => $invoiceId], [
                'client_id' => $client->id, 'recipient' => (string) $client->email,
                'snapshot' => ['clientName' => $client->name, 'planCode' => $subscription->plan_code, 'planName' => $subscription->plan_name,
                    'paidAmountCents' => (int) ($invoice['amount_paid'] ?? 0), 'monthlyPriceCents' => (int) config('listing-studio.plans.'.$subscription->plan_code.'.price_cents'),
                    'creditCents' => $granted, 'periodEnd' => $subscription->current_period_end->toIso8601String(),
                    'dashboardUrl' => rtrim((string) config('app.url'), '/').'/listing-studio/claim'],
                'status' => filter_var($client->email, FILTER_VALIDATE_EMAIL) ? 'queued' : 'needs_attention',
            ]);
        }
    }

    public function dispatchForSubscription(?int $subscriptionId): void
    {
        if (! $subscriptionId || ! config('listing-studio.subscription_receipts_enabled', true)) {
            return;
        }
        foreach (Receipt::where('subscription_id', $subscriptionId)->where('status', 'queued')->limit(10)->pluck('id') as $id) {
            $this->dispatch($id);
        }
    }

    private function dispatch(string $id): void
    {
        try {
            SendListingStudioSubscriptionReceipt::dispatch($id);
        } catch (\Throwable) {
            // The durable intent is picked up by the existing scheduler, without replaying a send.
        }
    }

    public function deliver(string $id): void
    {
        if (! config('listing-studio.subscription_receipts_enabled', true)) {
            return;
        }
        $receipt = $this->write(function () use ($id) {
            $row = Receipt::find($id);
            if (! $row || $row->status !== 'queued') {
                return null;
            }
            $subscription = ListingStudioSubscription::find($row->subscription_id);
            $client = User::find($row->client_id);
            if (! $subscription || ! $client || $client->role !== 'client' || ! $client->isAccountEligibleForAuthentication()
                || (int) $subscription->client_id !== (int) $client->id || $client->email !== $row->recipient
                || $subscription->sync_status !== 'synced' || $subscription->status !== 'active'
                || $subscription->stripe_account_id !== config('listing-studio.stripe_account_id')
                || $subscription->livemode !== (config('listing-studio.stripe_mode') === 'live')
                || ! $subscription->current_period_end?->isFuture()
                || $subscription->plan_code !== $row->snapshot['planCode']
                || $subscription->current_period_end?->toIso8601String() !== $row->snapshot['periodEnd']
                || $this->refunded($subscription, $row->invoice_id)) {
                $row->update(['status' => 'needs_attention', 'error_code' => 'receipt_account_changed']);
                return null;
            }
            $row->update(['status' => 'submitting', 'submission_started_at' => now()]);
            return $row;
        });
        if (! $receipt) {
            return;
        }
        try {
            $snapshot = $receipt->snapshot;
            $html = view('emails.listing_studio_subscription_confirmed', $this->presentation($snapshot))->render();
            $message = app(MessagingService::class)->sendEmail(['to' => $receipt->recipient,
                'subject' => 'Your '.$snapshot['planName'].' Listing Studio plan is ready', 'body_html' => $html,
                'body_text' => 'Hi '.$snapshot['clientName'].', your '.$snapshot['planName'].' Listing Studio plan is active. '
                    .$this->money($snapshot['creditCents']).' editing credit was added for this billing period. Billed today: '
                    .$this->money($snapshot['paidAmountCents']).'. Credit expires '.CarbonImmutable::parse($snapshot['periodEnd'])->format('M j, Y')
                    .', with no rollover. Open your account to see your current balance and manage billing: '.$snapshot['dashboardUrl'],
                'sender_name' => 'R/E Pro Photos', 'send_source' => 'LISTING_STUDIO_SUBSCRIPTION_CONFIRMED',
                'related_account_id' => $receipt->client_id, 'metadata' => ['listing_studio_subscription_receipt_id' => $receipt->id]]);
            $this->recordMessage($receipt, $message);
        } catch (\Throwable) {
            // A timeout is an unknown send outcome, not permission to send another receipt.
            $this->write(fn () => Receipt::whereKey($id)->where('status', 'submitting')
                ->update(['status' => 'needs_attention', 'error_code' => 'receipt_delivery_uncertain']));
        }
    }

    public function recover(int $limit = 100): int
    {
        if (! config('listing-studio.subscription_receipts_enabled', true)) {
            return 0;
        }
        $limit = max(1, min(100, $limit));
        $pending = Receipt::where('status', 'awaiting_delivery')->orWhere(fn ($query) => $query->where('status', 'submitting')
            ->where('submission_started_at', '<', now()->subMinutes(10)))->limit($limit)->get();
        foreach ($pending as $receipt) {
            $messages = Message::where('metadata->listing_studio_subscription_receipt_id', $receipt->id)
                ->where('related_account_id', $receipt->client_id)->where('to_address', $receipt->recipient)->limit(2)->get();
            if ($messages->count() === 1) {
                $this->recordMessage($receipt, $messages->first());
            } else {
                $this->write(fn () => Receipt::whereKey($receipt->id)->whereIn('status', ['submitting', 'awaiting_delivery'])
                    ->update(['status' => 'needs_attention', 'error_code' => 'receipt_delivery_uncertain']));
            }
        }
        $queued = Receipt::where('status', 'queued')->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($queued as $id) {
            $this->dispatch($id);
        }
        return $queued->count();
    }

    private function recordMessage(Receipt $receipt, Message $message): void
    {
        $status = $message->status === 'SENT' ? 'sent' : (in_array($message->status, ['QUEUED', 'SCHEDULED'], true) ? 'awaiting_delivery' : 'needs_attention');
        $this->write(fn () => Receipt::whereKey($receipt->id)->whereIn('status', ['submitting', 'awaiting_delivery'])
            ->update(['message_id' => $message->id, 'status' => $status, 'sent_at' => $status === 'sent' ? ($message->sent_at ?? now()) : null,
                'error_code' => $status === 'needs_attention' ? 'receipt_delivery_unavailable' : null]));
    }

    private function refunded(ListingStudioSubscription $subscription, string $invoiceId): bool
    {
        return DB::table('listing_studio_subscription_refunds')->where('stripe_account_id', $subscription->stripe_account_id)
            ->where('livemode', $subscription->livemode)->where('stripe_invoice_id', $invoiceId)
            ->where('status', 'succeeded')->where('amount_cents', '>', 0)->exists();
    }

    public function presentation(array $snapshot): array
    {
        return $snapshot + ['credit' => $this->money($snapshot['creditCents']), 'paid' => $this->money($snapshot['paidAmountCents']),
            'monthlyPrice' => $this->money($snapshot['monthlyPriceCents']), 'expires' => CarbonImmutable::parse($snapshot['periodEnd'])->format('F j, Y')];
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 ? 2 : 0);
    }

    private function write(callable $action): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($action), 'listing-studio-subscription-receipt');
    }
}
