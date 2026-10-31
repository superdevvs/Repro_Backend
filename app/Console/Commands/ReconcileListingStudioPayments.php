<?php

namespace App\Console\Commands;

use App\Models\ListingStudioCheckoutAttempt;
use App\Services\ListingStudio\StudioCheckoutService;
use App\Services\ListingStudio\StudioPaymentSync;
use App\Services\ListingStudio\StudioStripeGateway;
use Illuminate\Console\Command;

class ReconcileListingStudioPayments extends Command
{
    protected $signature = 'listing-studio:reconcile-payments {--limit=50} {--event= : Explicitly recover one authenticated Stripe event; otherwise inspect without writes}';

    protected $description = 'Inspect pending Studio payments read-only, or explicitly recover one verified Stripe event';

    public function handle(StudioStripeGateway $gateway, StudioCheckoutService $checkout, StudioPaymentSync $sync): int
    {
        if (! config('listing-studio.stripe_enabled')) {
            $this->info('Stripe synchronization is disabled.');

            return self::SUCCESS;
        }
        try {
            $checkout->verifyAccount();
        } catch (\Throwable) {
            $this->error('Stripe account could not be verified; no reconciliation performed.');

            return self::FAILURE;
        }
        if ($eventId = $this->option('event')) {
            if (! preg_match('/^evt_[A-Za-z0-9_]+$/', $eventId)) {
                $this->error('A valid Stripe event ID is required.');

                return self::FAILURE;
            }
            try {
                $event = $gateway->event($eventId);
                if (($event['id'] ?? '') !== $eventId) {
                    throw new \RuntimeException('Event identity mismatch.');
                }
                $result = $sync->handle($event);
                $this->line(json_encode(['eventRecovered' => (bool) ($result['handled'] ?? false)]));

                return ($result['handled'] ?? false) ? self::SUCCESS : self::FAILURE;
            } catch (\Throwable) {
                $this->error('Event recovery could not be completed; no new financial operation was submitted.');

                return self::FAILURE;
            }
        }
        $limit = max(1, min(100, (int) $this->option('limit')));
        $counts = ['inspected' => 0, 'awaitingVerifiedEvent' => 0, 'awaitingReview' => 0];
        // No DB lock/marker updates in this branch: even cache locks would be writes.
        foreach (ListingStudioCheckoutAttempt::whereNotIn('status', ['paid', 'expired', 'failed'])->whereNotNull('customer_id')->orderBy('updated_at')->limit($limit)->get() as $attempt) {
            try {
                $checkout->assertIdentity($attempt);
                $id = $attempt->session_id;
                if (! $id) {
                    $list = $gateway->checkouts($attempt->customer_id, $attempt->created_at->copy()->subMinutes(5)->timestamp);
                    $matches = collect($list['data'] ?? [])->filter(fn ($row) => data_get($row, 'metadata.studio_checkout_attempt') === $attempt->id)->values();
                    if (($list['has_more'] ?? true) || $matches->count() !== 1) {
                        $counts['awaitingReview']++;

                        continue;
                    }
                    $id = $matches[0]['id'];
                }
                $inspection = $sync->reconcileCheckout($attempt, $gateway->checkout($id), readOnly: true);
                $counts['inspected']++;
                if ($inspection['requiresVerifiedEvent']) {
                    $counts['awaitingVerifiedEvent']++;
                }
            } catch (\Throwable) {
                $counts['awaitingReview']++;
            }
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['awaitingReview'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
