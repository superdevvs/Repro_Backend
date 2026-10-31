<?php

namespace App\Services\ListingStudio;

use App\Models\ListingStudioCheckoutAttempt as Attempt;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioStripeCustomer as Customer;
use App\Models\ListingStudioSubscription;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudioCheckoutService
{
    public function __construct(private readonly StudioStripeGateway $gateway) {}

    public function order(User $client, string $orderId, string $key): array
    {
        return $this->locked($client->id, function () use ($client, $orderId, $key) {
            $this->eligible($client);
            $order = ListingStudioOrder::where('client_id', $client->id)->findOrFail($orderId);
            if ($order->funding_method !== 'card') {
                throw new StudioPaymentException('payment_not_required', 'This order uses editing credit.');
            }
            if ($order->paid_amount_cents > 0) {
                $order->loadMissing('item');
                $next = in_array($order->item?->status, ['completed', 'failed', 'cancelled'], true) ? 'none' : 'await_processing';

                return ['orderId' => $order->id, 'status' => $order->funding_status, 'url' => null, 'expiresAt' => null,
                    'order' => app(ListingStudioOrderService::class)->present($order), 'nextAction' => ['type' => $next]];
            }
            $this->enabled();
            if (! app(ListingStudioCatalogService::class)->available($order->kind, $order->service)) {
                throw new StudioPaymentException('service_unavailable', 'This editing service is temporarily unavailable.', 503);
            }
            if ($order->funding_status !== 'pending') {
                throw new StudioPaymentException('checkout_unavailable', 'This order is not awaiting payment.');
            }
            $attempt = $this->reserve($client, $key, 'order', $order->id, null, $order->total_cents, $order->currency);

            return $this->checkout($client, $attempt);
        });
    }

    public function subscription(User $client, string $plan, string $key): array
    {
        return $this->locked($client->id, function () use ($client, $plan, $key) {
            $this->eligible($client);
            $this->enabled();
            $definition = config('listing-studio.plans.'.$plan);
            if (! in_array($plan, ['starter', 'pro', 'studio'], true) || ! is_array($definition)) {
                throw new StudioPaymentException('validation_failed', 'Choose a supported plan.', 422);
            }
            $attempt = $this->reserve($client, $key, 'subscription', null, $plan, $definition['price_cents'], $definition['currency']);

            return $this->checkout($client, $attempt);
        });
    }

    public function portal(User $client): array
    {
        return $this->locked($client->id, function () use ($client) {
            $this->eligible($client);
            $this->verifyAccount();
            // Managing existing billing remains available when new purchases are disabled.
            $customer = $this->customer($client, false);
            $result = $this->gateway->portal($customer, $this->returnUrl('billing'));
            if (($result['customer'] ?? null) !== $customer || ($result['livemode'] ?? null) !== $this->identity()[1]) {
                throw new StudioPaymentException('billing_attention', 'Billing navigation could not be confirmed.');
            }
            $url = $this->navigationUrl($result['url'] ?? null, 'billing.stripe.com');

            return ['url' => $url, 'expiresAt' => null, 'nextAction' => ['type' => 'redirect']];
        });
    }

    private function reserve(User $client, string $key, string $kind, ?string $orderId, ?string $plan, int $amount, string $currency): Attempt
    {
        if (! Str::isUuid($key)) {
            throw new StudioPaymentException('validation_failed', 'A valid Idempotency-Key is required.', 422);
        }
        [$account, $live] = $this->identity();
        $hash = hash('sha256', json_encode([$kind, $orderId, $plan, $amount, $currency]));

        return $this->write(function () use ($client, $key, $kind, $orderId, $plan, $amount, $currency, $account, $live, $hash) {
            $this->eligible($client);
            $prior = Attempt::where('client_id', $client->id)->where('idempotency_key', $key)->first();
            if ($prior) {
                if (! hash_equals($prior->request_hash, $hash)) {
                    throw new StudioPaymentException('idempotency_conflict', 'This checkout key belongs to another request.');
                }
                $this->assertIdentity($prior);

                return $prior;
            }
            $active = Attempt::where('client_id', $client->id)->where('kind', $kind)
                ->when($orderId, fn ($query) => $query->where('order_id', $orderId))
                ->whereNotIn('status', $kind === 'subscription' ? ['expired', 'failed', 'paid'] : ['expired', 'failed'])->exists();
            if ($active && ($kind === 'subscription' || Attempt::where('order_id', $orderId)->whereNotIn('status', ['expired', 'failed'])->exists())) {
                throw new StudioPaymentException('checkout_in_progress', 'An existing checkout must be resolved before starting another.');
            }
            if ($kind === 'subscription' && ListingStudioSubscription::where('client_id', $client->id)->whereNotIn('status', ['canceled', 'incomplete_expired'])->exists()) {
                throw new StudioPaymentException('subscription_exists', 'Manage your existing subscription in billing settings.');
            }

            return Attempt::create(['client_id' => $client->id, 'order_id' => $orderId, 'plan_code' => $plan, 'kind' => $kind,
                'idempotency_key' => $key, 'request_hash' => $hash, 'account_id' => $account, 'livemode' => $live,
                'currency' => strtolower($currency), 'amount_cents' => $amount, 'status' => 'pending']);
        });
    }

    private function checkout(User $client, Attempt $attempt): array
    {
        $this->assertIdentity($attempt);
        if ($attempt->status === 'expired') {
            $this->expired();
        }
        if ($attempt->status === 'failed') {
            throw new StudioPaymentException('checkout_failed', 'This checkout failed. Start a new checkout.', 409, ['retryWithNewKey' => true]);
        }
        $this->verifyAccount();
        if ($attempt->session_id) {
            $session = $this->gateway->checkout($attempt->session_id);
            app(StudioPaymentSync::class)->reconcileCheckout($attempt, $session);

            return $this->present($attempt->fresh());
        }
        $customer = $this->customer($client, true);
        if ($attempt->kind === 'subscription' && ! $attempt->submission_started_at) {
            $subscriptions = $this->gateway->subscriptions($customer);
            if (! array_key_exists('has_more', $subscriptions) || $subscriptions['has_more'] || ! is_array($subscriptions['data'] ?? null)
                || collect($subscriptions['data'])->contains(fn ($sub) => ! in_array($sub['status'] ?? '', ['canceled', 'incomplete_expired'], true))) {
                throw new StudioPaymentException('subscription_exists', 'Resolve your existing subscription in billing settings.');
            }
        }
        $this->assertRetryWindow($attempt->submission_started_at);
        if (! $attempt->parameters) {
            $metadata = ['studio_checkout_attempt' => $attempt->id, 'studio_kind' => $attempt->kind];
            if ($attempt->order_id) {
                $metadata['studio_order_id'] = $attempt->order_id;
            }
            $parameters = ['mode' => $attempt->kind === 'order' ? 'payment' : 'subscription', 'customer' => $customer,
                'client_reference_id' => $attempt->id, 'metadata' => $metadata,
                'success_url' => $this->returnUrl($attempt->kind === 'order' ? 'photos' : 'billing', $attempt->order_id, 'returned'),
                'cancel_url' => $this->returnUrl($attempt->kind === 'order' ? 'photos' : 'billing', $attempt->order_id, 'cancelled'),
                'expires_at' => now()->addHour()->timestamp, 'payment_method_types' => ['card']];
            if ($attempt->kind === 'order') {
                $parameters['payment_intent_data'] = ['metadata' => $metadata];
                $parameters['line_items'] = [['quantity' => 1, 'price_data' => ['currency' => $attempt->currency,
                    'unit_amount' => $attempt->amount_cents, 'product_data' => ['name' => 'Listing Studio photo edit']]]];
            } else {
                $price = config('listing-studio.plans.'.$attempt->plan_code.'.'.($attempt->livemode ? 'live_price' : 'test_price'));
                if (! is_string($price) || ! str_starts_with($price, 'price_')) {
                    throw new StudioPaymentException('checkout_unavailable', 'This plan is temporarily unavailable.', 503);
                }
                $canonicalPrice = $this->gateway->price($price);
                if (($canonicalPrice['id'] ?? '') !== $price || ($canonicalPrice['livemode'] ?? null) !== $attempt->livemode
                    || ($canonicalPrice['active'] ?? null) !== true || ($canonicalPrice['unit_amount'] ?? null) !== $attempt->amount_cents
                    || ($canonicalPrice['currency'] ?? '') !== $attempt->currency || data_get($canonicalPrice, 'recurring.interval') !== 'month'
                    || data_get($canonicalPrice, 'recurring.interval_count') !== 1) {
                    throw new StudioPaymentException('checkout_unavailable', 'This plan needs billing configuration review.', 503);
                }
                $parameters['line_items'] = [['quantity' => 1, 'price' => $price]];
                $parameters['subscription_data'] = ['metadata' => $metadata];
            }
            $this->write(fn () => $attempt->update(['customer_id' => $customer, 'parameters' => $parameters, 'submission_started_at' => now(), 'status' => 'submitting']));
        }
        try {
            $session = $this->gateway->createCheckout($attempt->parameters, 'studio-checkout-'.$attempt->id);
            app(StudioPaymentSync::class)->reconcileCheckout($attempt, $session);
        } catch (\Throwable $exception) {
            // Even a provider 500 may cache an accepted create. Keep the durable key and exact request.
            $this->write(fn () => Attempt::whereKey($attempt->id)->whereNull('session_id')->update(['status' => 'awaiting_reconciliation']));
            if ($exception instanceof StudioPaymentException) {
                throw $exception;
            }
            throw new StudioPaymentException('awaiting_reconciliation', 'Checkout is awaiting confirmation. Keep this order and try again later.', 503);
        }

        return $this->present($attempt->fresh());
    }

    private function customer(User $client, bool $create): string
    {
        [$account, $live] = $this->identity();
        $binding = Customer::where(['client_id' => $client->id, 'account_id' => $account, 'livemode' => $live])->first();
        if (! $binding) {
            $known = ListingStudioSubscription::where(['client_id' => $client->id, 'stripe_account_id' => $account, 'livemode' => $live])
                ->pluck('stripe_customer_id')->unique()->values();
            if ($known->count() === 1 && ListingStudioSubscription::where(['stripe_account_id' => $account, 'livemode' => $live, 'stripe_customer_id' => $known->first()])->where('client_id', '!=', $client->id)->exists()) {
                throw new StudioPaymentException('billing_attention', 'Billing needs account review.');
            }
            if ($known->count() > 1) {
                throw new StudioPaymentException('billing_attention', 'Billing needs account review.');
            }
            if (! $create && $known->isEmpty()) {
                throw new StudioPaymentException('billing_unavailable', 'No billing account is available.');
            }
            $bindingId = (string) Str::uuid();
            $binding = $this->write(fn () => Customer::create(['id' => $bindingId, 'client_id' => $client->id, 'account_id' => $account, 'livemode' => $live,
                'customer_id' => $known->first(), 'create_parameters' => ['email' => $client->email, 'name' => $client->name,
                    'metadata' => ['studio_client_id' => (string) $client->id, 'studio_customer_binding' => $bindingId]]]));
        }
        if ($binding->customer_id) {
            $remote = $this->gateway->customer($binding->customer_id);
        } else {
            $this->assertRetryWindow($binding->submission_started_at);
            if (! $binding->submission_started_at) {
                $this->write(fn () => $binding->update(['submission_started_at' => now()]));
            }
            $remote = $this->gateway->createCustomer($binding->create_parameters, 'studio-customer-'.$binding->id);
        }
        if (! preg_match('/^cus_[A-Za-z0-9_]+$/', (string) ($remote['id'] ?? '')) || ! array_key_exists('livemode', $remote)
            || $remote['livemode'] !== $live || ! empty($remote['deleted'])
            || ($binding->customer_id && $binding->customer_id !== $remote['id'])) {
            throw new StudioPaymentException('billing_attention', 'Billing ownership could not be confirmed.');
        }
        if (! $binding->customer_id) {
            $this->write(fn () => $binding->update(['customer_id' => $remote['id']]));
        }

        return $remote['id'];
    }

    public function recoverCustomer(Customer $binding): bool
    {
        $this->assertIdentity($binding);
        if ($binding->customer_id) {
            return true;
        }
        $list = $this->gateway->customers((string) data_get($binding->create_parameters, 'email'));
        $matches = collect($list['data'] ?? [])->filter(fn ($row) => data_get($row, 'metadata.studio_customer_binding') === $binding->id
            && data_get($row, 'metadata.studio_client_id') === (string) $binding->client_id && ($row['livemode'] ?? null) === $binding->livemode && empty($row['deleted']))->values();
        if (($list['has_more'] ?? true) || $matches->count() !== 1 || ! preg_match('/^cus_[A-Za-z0-9_]+$/', $matches[0]['id'] ?? '')) {
            return false;
        }
        $this->write(fn () => Customer::whereKey($binding->id)->whereNull('customer_id')->update(['customer_id' => $matches[0]['id']]));

        return true;
    }

    public function present(Attempt $attempt): array
    {
        if ($attempt->status === 'expired') {
            $this->expired();
        }
        $redirect = $attempt->status === 'open';

        return ['orderId' => $attempt->order_id, 'status' => $attempt->status,
            'url' => $redirect ? $this->navigationUrl($attempt->checkout_url, 'checkout.stripe.com') : null,
            'expiresAt' => $attempt->expires_at?->toIso8601String(),
            'nextAction' => ['type' => $redirect ? 'redirect' : ($attempt->status === 'paid' ? ($attempt->kind === 'subscription' ? 'none' : 'await_processing') : 'await_payment')]];
    }

    public function verifyAccount(): void
    {
        [$account] = $this->identity();
        if (($this->gateway->account()['id'] ?? '') !== $account) {
            throw new StudioPaymentException('billing_attention', 'Billing configuration needs review.', 503);
        }
    }

    public function identity(): array
    {
        $account = (string) config('listing-studio.stripe_account_id');
        $mode = config('listing-studio.stripe_mode');
        if (! preg_match('/^acct_[A-Za-z0-9_]+$/', $account) || ! in_array($mode, ['test', 'live'], true)) {
            throw new StudioPaymentException('checkout_unavailable', 'Billing is temporarily unavailable.', 503);
        }

        return [$account, $mode === 'live'];
    }

    public function assertIdentity(object $attempt): void
    {
        [$account, $live] = $this->identity();
        if ($attempt->account_id !== $account || $attempt->livemode !== $live) {
            throw new StudioPaymentException('billing_attention', 'This payment requires account reconciliation.');
        }
    }

    public function assertRetryWindow(mixed $started): void
    {
        if ($started && $started->lessThanOrEqualTo(now()->subHours(23))) {
            throw new StudioPaymentException('awaiting_reconciliation', 'This checkout needs reconciliation before another payment can start.');
        }
    }

    public function navigationUrl(mixed $url, string $host): string
    {
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $host
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PORT)) {
            throw new StudioPaymentException('checkout_unavailable', 'Billing navigation is unavailable.', 503);
        }

        return $url;
    }

    private function returnUrl(string $tab, ?string $orderId = null, ?string $status = null): string
    {
        $query = ['tab' => $tab];
        if ($orderId !== null) {
            $query['order'] = $orderId;
        }
        if ($status !== null) {
            $query['checkout'] = $status;
        }

        return rtrim((string) config('app.url'), '/').'/listing-studio?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function expired(): never
    {
        throw new StudioPaymentException('checkout_expired', 'This checkout expired. Start a new checkout.', 409, ['retryWithNewKey' => true]);
    }

    private function enabled(): void
    {
        if (! config('listing-studio.paid_enabled') || ! config('listing-studio.paid_checkout_enabled') || ! config('listing-studio.stripe_enabled')) {
            throw new StudioPaymentException('checkout_unavailable', 'New checkouts are temporarily unavailable.', 503);
        }
    }

    private function eligible(User $client): void
    {
        $fresh = User::find($client->id);
        if (! $fresh || $fresh->role !== 'client' || collect($fresh->secondary_roles ?? [])->contains(fn ($role) => $role !== 'client') || ! $fresh->isAccountEligibleForAuthentication()) {
            throw new StudioPaymentException('account_ineligible', 'Use an active client account.', 403);
        }
    }

    private function write(callable $callback): mixed
    {
        return LockedWrite::run(fn () => DB::transaction($callback), 'listing-studio-payment');
    }

    private function locked(int $clientId, callable $callback): mixed
    {
        $lock = Cache::store('database')->lock('studio-client-checkout:'.$clientId, 180);
        if (! $lock->get()) {
            throw new StudioPaymentException('checkout_in_progress', 'Checkout is already being prepared.');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
