<?php

namespace App\Services\ListingStudio;

use Illuminate\Support\Facades\Http;

class StripeRefundGateway
{
    public const API_VERSION = '2025-09-30.clover';

    public function refund(string $id): array
    {
        return $this->get('refunds/'.rawurlencode($id), ['expand' => ['charge']]);
    }

    public function invoicePayments(string $paymentIntentId): array
    {
        // Multiple invoice allocations are deliberately left to manual reconciliation.
        return $this->get('invoice_payments', [
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntentId],
            'limit' => 2, 'expand' => ['data.invoice'],
        ]);
    }

    public function subscription(string $id): array
    {
        return $this->get('subscriptions/'.rawurlencode($id), ['expand' => ['customer']]);
    }

    private function get(string $resource, array $query): array
    {
        $key = trim((string) config('listing-studio.stripe_secret_key'));
        if ($key === '') {
            throw new \RuntimeException('Listing Studio Stripe key is not configured.');
        }

        // At most three reads, each with two 15-second attempts, fit the 180-second lock.
        // Pin this new resolver without changing the account or webhook API version.
        return Http::withToken($key)->acceptJson()->withHeaders(['Stripe-Version' => self::API_VERSION])
            ->connectTimeout(5)->timeout(15)->retry(2, 250)
            ->get('https://api.stripe.com/v1/'.$resource, $query)->throw()->json();
    }
}
