<?php

namespace App\Services\ListingStudio;

use App\Jobs\SendListingStudioAccountSetup;
use App\Models\ListingStudioAccountSetup;
use App\Models\ListingStudioSubscription;
use App\Models\ServiceGroup;
use App\Models\User;
use App\Services\Users\DashboardOnboardingService;
use App\Support\LockedWrite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synchronizes Stripe billing, client accounts and credits independently of staff requests. */
class StripeSubscriptionSync
{
    public const EVENTS = [
        'checkout.session.completed', 'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed', 'checkout.session.expired',
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'customer.subscription.paused', 'customer.subscription.resumed',
        'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required',
        'refund.created', 'refund.updated', 'refund.failed',
    ];

    public function __construct(private readonly StripeSubscriptionGateway $gateway) {}

    /** null means the event is not established as belonging to Listing Studio. */
    public function handle(array $event): ?array
    {
        if (! config('listing-studio.stripe_enabled') || ! in_array($event['type'] ?? '', self::EVENTS, true)
            || str_starts_with($event['type'], 'refund.')) {
            return null;
        }
        $account = trim((string) config('listing-studio.stripe_account_id'));
        $livemode = config('listing-studio.stripe_mode') === 'live';
        if ($account === '' || ! array_key_exists('livemode', $event)
            || (bool) $event['livemode'] !== $livemode
            || (! empty($event['account']) && $event['account'] !== $account)) {
            return null;
        }
        $subscriptionId = $this->subscriptionId($event);
        if ($subscriptionId === '' || ! preg_match('/^evt_[a-zA-Z0-9_]+$/', (string) ($event['id'] ?? ''))) {
            return null;
        }

        $identity = ['stripe_account_id' => $account, 'livemode' => $livemode];
        $eventIdentity = $identity + ['stripe_event_id' => $event['id']];
        $prior = DB::table('listing_studio_stripe_events')->where($eventIdentity)->first();
        if ($prior) {
            $this->queueAccountSetup(ListingStudioSubscription::find($prior->subscription_id)?->client_id);

            return ['status' => 'success', 'handled' => true, 'outcome' => 'listing_studio_already_synced'];
        }

        // Lock before the remote read: two canonical fetches must not commit in reverse order.
        $lock = Cache::lock('listing-studio-subscription:'.hash('sha256', $account.':'.(int) $livemode.':'.$subscriptionId), 120);
        if (! $lock->get()) {
            throw new \RuntimeException('Listing Studio synchronization is already running.');
        }
        try {
            $subscription = $this->gateway->subscription($subscriptionId);
            if (($subscription['id'] ?? '') !== $subscriptionId
                || ! array_key_exists('livemode', $subscription)
                || (bool) $subscription['livemode'] !== $livemode) {
                throw new \RuntimeException('Stripe returned a different subscription identity or mode.');
            }
            $key = $identity + ['stripe_subscription_id' => $subscriptionId];
            $existing = ListingStudioSubscription::where($key)->first();
            $items = data_get($subscription, 'items.data', []);
            $item = count($items) === 1 ? $items[0] : [];
            $priceId = $this->id($item['price'] ?? null);
            $planCode = $this->planForPrice($priceId, $livemode);
            if (! $existing && $planCode === null) {
                return null;
            }
            $reason = null;
            if ($planCode === null || count($items) !== 1 || (int) ($item['quantity'] ?? 0) !== 1) {
                $reason = 'The subscription has an unsupported price or quantity. Review it in Stripe.';
            }
            $customer = $subscription['customer'] ?? null;
            $invoice = $subscription['latest_invoice'] ?? null;
            if (! is_array($customer) || $this->id($customer) === '') {
                throw new \RuntimeException('Stripe did not return the expanded subscription customer.');
            }
            if (is_string($invoice)) {
                throw new \RuntimeException('Stripe did not return the expanded latest invoice.');
            }
            if ($existing && $existing->stripe_customer_id !== $this->id($customer)) {
                throw new \RuntimeException('The subscription customer identity changed.');
            }
            // A paid zero-dollar invoice is valid when an allowed promotion covers the plan.
            $paid = is_array($invoice) && ($invoice['status'] ?? '') === 'paid'
                && isset($invoice['amount_paid']) && is_numeric($invoice['amount_paid']) && (int) $invoice['amount_paid'] >= 0
                && $this->invoiceSubscriptionId($invoice) === $subscriptionId
                && $this->id($invoice['customer'] ?? null) === $this->id($customer);
            $confirmedEnrollment = $paid && ($subscription['status'] ?? '') === 'active' && $reason === null;
            $email = strtolower(trim((string) ($customer['email'] ?? '')));
            $name = trim((string) ($customer['name'] ?? ''));
            $snapshot = [
                'stripe_customer_id' => $this->id($customer), 'stripe_price_id' => $priceId ?: null,
                'customer_name' => mb_substr($name, 0, 255) ?: null, 'customer_email' => mb_substr($email, 0, 255) ?: null,
                'plan_code' => $planCode, 'plan_name' => $planCode ? config('listing-studio.plans.'.$planCode.'.name') : null,
                'status' => (string) ($subscription['status'] ?? 'unknown'),
                'amount_cents' => data_get($item, 'price.unit_amount'),
                'currency' => data_get($item, 'price.currency'),
                'billing_interval' => data_get($item, 'price.recurring.interval'),
                'billing_interval_count' => data_get($item, 'price.recurring.interval_count'),
                // Recent Stripe API versions put the period on subscription items.
                'current_period_start' => $this->date($item['current_period_start'] ?? $subscription['current_period_start'] ?? null),
                'current_period_end' => $this->date($item['current_period_end'] ?? $subscription['current_period_end'] ?? null),
                'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
                'canceled_at' => $this->date($subscription['canceled_at'] ?? null),
                'latest_invoice_id' => $this->id($invoice) ?: null, 'latest_invoice_status' => $invoice['status'] ?? null,
            ];
            $paidAt = $paid ? $this->date(data_get($invoice, 'status_transitions.paid_at') ?? $invoice['created'] ?? null) : null;

            $credits = app(ListingStudioCreditService::class);
            $creditInvoices = $credits->prepareInvoices($event, $subscription);

            $record = LockedWrite::run(fn () => DB::transaction(function () use ($key, $snapshot, $paidAt, $confirmedEnrollment, $email, $name, $reason, $eventIdentity, $event, $credits, $creditInvoices) {
                $record = ListingStudioSubscription::firstOrNew($key);
                // A retry after another transaction won cannot create a second account or outbox.
                if (DB::table('listing_studio_stripe_events')->where($eventIdentity)->exists()) {
                    return $record;
                }
                $record->fill($snapshot);
                if ($paidAt && (! $record->last_paid_at || $paidAt->greaterThan($record->last_paid_at))) {
                    $record->last_paid_at = $paidAt;
                }
                $client = $record->client_id ? User::withTrashed()->find($record->client_id) : null;
                if ($record->client_id && (! $client || ! $this->eligibleClient($client))) {
                    $reason ??= 'The linked dashboard account needs administrator review.';
                }
                if (! $client && $confirmedEnrollment) {
                    [$client, $created, $identityReason] = $this->resolveClient($email, $name);
                    $reason ??= $identityReason;
                    if ($client) {
                        $record->client_id = $client->id;
                        $record->account_created = $created;
                    }
                }
                $record->sync_status = $reason ? 'needs_attention' : ($client ? 'synced' : 'awaiting_payment');
                $record->attention_reason = $reason;
                $record->save();
                $credits->reconcileInvoices($record, $creditInvoices);
                DB::table('listing_studio_stripe_events')->insert($eventIdentity + [
                    'event_type' => $event['type'], 'subscription_id' => $record->id, 'processed_at' => now(),
                ]);

                return $record;
            }), 'listing-studio-stripe-sync');

            $this->queueAccountSetup($record->client_id);

            return ['status' => 'success', 'handled' => true, 'outcome' => 'listing_studio_synced'];
        } finally {
            $lock->release();
        }
    }

    private function resolveClient(string $email, string $name): array
    {
        if ($email === '' || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, false, 'The paid subscription has no valid customer email. Add one in Stripe, then retry synchronization.'];
        }
        // Include deleted and mixed-case accounts so no duplicate or privileged account is adopted.
        $matches = User::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->limit(2)->get();
        if ($matches->isNotEmpty()) {
            if ($matches->count() !== 1 || ! $this->eligibleClient($matches->first())) {
                return [null, false, 'This email matches an unavailable or staff dashboard account. An administrator must resolve the account match.'];
            }

            return [$matches->first(), false, null];
        }
        $client = User::create([
            'name' => mb_substr($name ?: Str::before($email, '@'), 0, 255),
            'username' => 'listing_'.Str::lower(Str::random(24)), 'email' => $email,
            'password' => Str::random(80), 'password_reset_required' => true,
            'role' => 'client', 'secondary_roles' => [], 'account_status' => 'active',
            'email_verified_at' => null,
            'metadata' => app(DashboardOnboardingService::class)->applyEligibility([], 'client', 'listing_studio_signup'),
        ]);
        $client->forceFill(['email_verification_required_at' => now(), 'email_verified_email' => null])->save();
        if ($group = ServiceGroup::getDefaultGroup()) {
            $client->serviceGroups()->sync([$group->id]);
        }
        ListingStudioAccountSetup::create(['client_id' => $client->id, 'status' => 'pending']);

        return [$client, true, null];
    }

    private function eligibleClient(User $user): bool
    {
        return strtolower(trim((string) $user->role)) === 'client'
            && collect($user->secondary_roles ?? [])->every(fn ($role) => strtolower(trim((string) $role)) === 'client')
            && $user->isAccountEligibleForAuthentication();
    }

    private function queueAccountSetup(?int $clientId): void
    {
        $setup = $clientId ? ListingStudioAccountSetup::where('client_id', $clientId)
            ->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)->first() : null;
        if ($setup) {
            SendListingStudioAccountSetup::dispatch($setup->id);
        }
    }

    private function subscriptionId(array $event): string
    {
        $object = data_get($event, 'data.object', []);
        if (str_starts_with($event['type'], 'customer.subscription.')) {
            return $this->id($object);
        }
        if (str_starts_with($event['type'], 'checkout.session.')) {
            return ($object['mode'] ?? '') === 'subscription' ? $this->id($object['subscription'] ?? null) : '';
        }

        return $this->invoiceSubscriptionId($object);
    }

    private function invoiceSubscriptionId(array $invoice): string
    {
        return $this->id(data_get($invoice, 'parent.subscription_details.subscription') ?? $invoice['subscription'] ?? null);
    }

    private function planForPrice(string $priceId, bool $livemode): ?string
    {
        foreach (config('listing-studio.plans', []) as $code => $plan) {
            $allowed = $plan[$livemode ? 'live_price' : 'test_price'] ?? null;
            if (is_string($allowed) && $allowed !== '' && hash_equals($allowed, $priceId)) {
                return $code;
            }
        }

        return null;
    }

    private function id(mixed $value): string
    {
        return is_string($value) ? $value : (string) data_get($value, 'id', '');
    }

    private function date(mixed $timestamp): ?CarbonImmutable
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? CarbonImmutable::createFromTimestampUTC((int) $timestamp) : null;
    }
}
