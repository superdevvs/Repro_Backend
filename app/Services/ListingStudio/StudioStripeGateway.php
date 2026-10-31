<?php

namespace App\Services\ListingStudio;

use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/** All provider calls are outside SQLite write transactions. No SDK automatic POST retry. */
class StudioStripeGateway
{
    private ?StripeClient $client = null;

    public function account(): array
    {
        return $this->client()->accounts->retrieve()->toArray();
    }

    public function event(string $id): array
    {
        return $this->client()->events->retrieve($id)->toArray();
    }

    public function price(string $id): array
    {
        return $this->client()->prices->retrieve($id)->toArray();
    }

    public function customers(string $email): array
    {
        return $this->client()->customers->all(['email' => $email, 'limit' => 100])->toArray();
    }

    public function customer(string $id): array
    {
        return $this->client()->customers->retrieve($id)->toArray();
    }

    public function createCustomer(array $parameters, string $key): array
    {
        return $this->client()->customers->create($parameters, ['idempotency_key' => $key])->toArray();
    }

    public function createCheckout(array $parameters, string $key): array
    {
        return $this->client()->checkout->sessions->create($parameters, ['idempotency_key' => $key])->toArray();
    }

    public function checkout(string $id): array
    {
        return $this->client()->checkout->sessions->retrieve($id, ['expand' => ['payment_intent', 'line_items', 'subscription']])->toArray();
    }

    public function checkouts(string $customer, int $since): array
    {
        return $this->client()->checkout->sessions->all(['customer' => $customer, 'created' => ['gte' => $since], 'limit' => 100])->toArray();
    }

    public function refunds(string $intent): array
    {
        return $this->client()->refunds->all(['payment_intent' => $intent, 'limit' => 100])->toArray();
    }

    public function subscriptions(string $customer): array
    {
        return $this->client()->subscriptions->all(['customer' => $customer, 'status' => 'all', 'limit' => 100])->toArray();
    }

    public function portal(string $customer, string $returnUrl): array
    {
        return $this->client()->billingPortal->sessions->create(['customer' => $customer, 'return_url' => $returnUrl])->toArray();
    }

    public function refund(string $id): array
    {
        return $this->client()->refunds->retrieve($id, ['expand' => ['charge', 'payment_intent']])->toArray();
    }

    public function createRefund(array $parameters, string $key): array
    {
        return $this->client()->refunds->create($parameters, ['idempotency_key' => $key])->toArray();
    }

    protected function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }
        $key = trim((string) config('listing-studio.stripe_secret_key'));
        if ($key === '') {
            throw new StudioPaymentException('checkout_unavailable', 'Billing is temporarily unavailable.', 503);
        }
        $http = new CurlClient;
        $http->setConnectTimeout(5);
        $http->setTimeout(15);
        // Stripe's default global transport is replaced only with bounded network timings.
        \Stripe\ApiRequestor::setHttpClient($http);

        return $this->client = new StripeClient(['api_key' => $key, 'stripe_version' => '2025-09-30.clover', 'max_network_retries' => 0]);
    }
}
