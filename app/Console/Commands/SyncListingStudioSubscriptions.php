<?php

namespace App\Console\Commands;

use App\Services\ListingStudio\StripeSubscriptionGateway;
use App\Services\ListingStudio\StripeSubscriptionSync;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncListingStudioSubscriptions extends Command
{
    protected $signature = 'listing-studio:sync-subscriptions {--subscription= : Reconcile one subscription} {--after= : Stripe pagination cursor} {--limit=100 : One page, maximum 100}';

    protected $description = 'Import canonical Listing Studio billing state without modifying Stripe';

    public function handle(StripeSubscriptionGateway $gateway, StripeSubscriptionSync $sync): int
    {
        if (! config('listing-studio.stripe_enabled')) {
            $this->error('Listing Studio Stripe synchronization is disabled.');

            return self::FAILURE;
        }
        $id = $this->option('subscription');
        $page = $id ? ['data' => [['id' => $id]], 'has_more' => false]
            : $gateway->subscriptions($this->option('after'), (int) $this->option('limit'));
        $synced = 0;
        foreach ($page['data'] ?? [] as $subscription) {
            $result = $sync->handle([
                'id' => 'evt_manual_'.str_replace('-', '', (string) Str::uuid()),
                'type' => 'customer.subscription.updated',
                'livemode' => config('listing-studio.stripe_mode') === 'live',
                'data' => ['object' => ['id' => $subscription['id']]],
            ]);
            $synced += (int) ($result['handled'] ?? false);
        }
        $this->info('Listing Studio subscriptions synchronized: '.$synced);
        if ($page['has_more'] ?? false) {
            $this->line('Continue with --after='.end($page['data'])['id']);
        }

        return self::SUCCESS;
    }
}
