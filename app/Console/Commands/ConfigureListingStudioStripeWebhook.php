<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\StripeSubscriptionSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Stripe\StripeClient;

/** Explicit, read-only by default; reuse the existing dashboard signing secret. */
class ConfigureListingStudioStripeWebhook extends Command
{
    protected $signature = 'listing-studio:configure-stripe-webhook {endpoint : Existing Stripe webhook endpoint ID} {--apply : Add missing events after reviewing the proposed configuration}';

    protected $description = 'Verify the Listing Studio Stripe account and plans, then optionally add subscription events to the existing dashboard webhook';

    public function handle(): int
    {
        try {
            $key = trim((string) config('listing-studio.stripe_secret_key'));
            $mode = config('listing-studio.stripe_mode');
            if ($key === '' || ! in_array($mode, ['live', 'test'], true)) {
                throw new \RuntimeException('Configure the Listing Studio Stripe key and mode first.');
            }
            $stripe = $this->stripeClient($key);
            $account = $stripe->accounts->retrieve();
            if ($account->id !== config('listing-studio.stripe_account_id')) {
                throw new \RuntimeException('The Stripe account does not match the configured Listing Studio account.');
            }
            foreach (config('listing-studio.plans', []) as $code => $plan) {
                $priceId = $plan[$mode.'_price'] ?? null;
                if (! $priceId) {
                    throw new \RuntimeException('A price is missing for plan '.$code.' in '.$mode.' mode.');
                }
                $price = $stripe->prices->retrieve($priceId);
                if (! $price->active || (bool) $price->livemode !== ($mode === 'live') || ! $price->recurring) {
                    throw new \RuntimeException('The price for '.$code.' is inactive, non-recurring, or in the wrong mode.');
                }
                if (($plan['price_cents'] ?? 0) <= 0 || $price->unit_amount !== $plan['price_cents']
                    || $price->currency !== 'usd' || $price->recurring->interval !== 'month'
                    || $price->recurring->interval_count !== 1) {
                    throw new \RuntimeException('The price for '.$code.' does not match the configured monthly USD pricing.');
                }
                $this->line($code.': '.$price->id.' '.strtoupper($price->currency).' '.$price->unit_amount.' minor units / '.$price->recurring->interval);
            }
            $endpoint = $stripe->webhookEndpoints->retrieve((string) $this->argument('endpoint'));
            $allowedUrls = ['https://reprodashboard.com/api/webhooks/stripe', 'https://api.reprodashboard.com/api/webhooks/stripe'];
            if (! in_array($endpoint->url, $allowedUrls, true) || $endpoint->status !== 'enabled'
                || (bool) $endpoint->livemode !== ($mode === 'live')) {
                throw new \RuntimeException('Use an enabled dashboard webhook in the configured Stripe mode.');
            }
            if (! config('services.stripe.webhook_secret')) {
                throw new \RuntimeException('The existing dashboard webhook signing secret is missing.');
            }
            $existing = $endpoint->enabled_events ?? [];
            $events = in_array('*', $existing, true) ? ['*'] : array_values(array_unique(array_merge($existing, StripeSubscriptionSync::EVENTS)));
            $missing = $events === ['*'] ? [] : array_values(array_diff($events, $existing));
            $this->line('Endpoint: '.$endpoint->id.' '.$endpoint->url);
            $this->line('Events to add: '.($missing === [] ? 'none' : implode(', ', $missing)));
            if (! $this->option('apply')) {
                $this->info('Read-only check complete. No Stripe settings were changed.');

                return self::SUCCESS;
            }
            if (! config('listing-studio.stripe_enabled') || ! Schema::hasTable('listing_studio_subscriptions')
                || ! Schema::hasTable('listing_studio_stripe_events') || ! Schema::hasTable('listing_studio_account_setups')
                || ! Schema::hasTable('listing_studio_subscription_refunds') || ! Schema::hasTable('listing_studio_credit_entries')) {
                throw new \RuntimeException('Deploy the migration and enable Listing Studio synchronization before adding webhook events.');
            }
            if ($missing !== []) {
                $stripe->webhookEndpoints->update($endpoint->id, ['enabled_events' => $events]);
            }
            $this->info('Listing Studio subscription events are enabled; existing events and the signing secret were preserved.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Never print provider exception messages, request bodies, or credential-bearing diagnostics.
            $this->error($exception instanceof \RuntimeException && ! $exception instanceof \Stripe\Exception\ApiErrorException
                ? $exception->getMessage() : 'Stripe verification failed. Check account access and configuration before retrying.');

            return self::FAILURE;
        }
    }

    protected function stripeClient(string $key): StripeClient
    {
        return new StripeClient(['api_key' => $key, 'max_network_retries' => 2]);
    }
}
