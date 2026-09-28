<?php

namespace App\Services\ListingStudio;

use Illuminate\Support\Facades\Http;

class StripeSubscriptionGateway
{
    private const API_VERSION = '2025-09-30.clover';

    public function subscription(string $id): array
    {
        $key = trim((string) config('listing-studio.stripe_secret_key'));
        if ($key === '') {
            throw new \RuntimeException('Listing Studio Stripe key is not configured.');
        }

        // Bound all retries well below the synchronization lock's 120-second lease.
        return Http::withToken($key)->withHeaders(['Stripe-Version' => self::API_VERSION])->acceptJson()->connectTimeout(5)->timeout(15)->retry(2, 250)
            ->get('https://api.stripe.com/v1/subscriptions/'.rawurlencode($id), ['expand' => ['customer', 'latest_invoice']])
            ->throw()->json();
    }

    public function subscriptions(?string $after = null, int $limit = 100): array
    {
        $key = trim((string) config('listing-studio.stripe_secret_key'));
        if ($key === '') {
            throw new \RuntimeException('Listing Studio Stripe key is not configured.');
        }

        return Http::withToken($key)->withHeaders(['Stripe-Version' => self::API_VERSION])->acceptJson()->connectTimeout(5)->timeout(15)->retry(2, 250)
            ->get('https://api.stripe.com/v1/subscriptions', array_filter([
                'status' => 'all', 'limit' => max(1, min($limit, 100)), 'starting_after' => $after,
            ]))->throw()->json();
    }

    public function invoice(string $id): array
    {
        $key = trim((string) config('listing-studio.stripe_secret_key'));
        if ($key === '') {
            throw new \RuntimeException('Listing Studio Stripe key is not configured.');
        }

        return Http::withToken($key)->withHeaders(['Stripe-Version' => self::API_VERSION])->acceptJson()->connectTimeout(5)->timeout(15)->retry(2, 250)
            ->get('https://api.stripe.com/v1/invoices/'.rawurlencode($id))->throw()->json();
    }
}
