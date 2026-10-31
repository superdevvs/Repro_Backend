<?php

namespace Tests\Feature;

use App\Models\ListingStudioBillingRequest;
use App\Models\ListingStudioSubscription;
use App\Models\ListingStudioSubscriptionAction as Action;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioCreditService;
use App\Services\ListingStudio\StripeSubscriptionGateway;
use App\Services\ListingStudio\StripeSubscriptionSync;
use App\Services\ListingStudio\StudioPaymentException;
use App\Services\ListingStudio\StudioStripeGateway;
use App\Services\ListingStudio\StudioSubscriptionActionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ListingStudioSubscriptionActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $actor;

    private ListingStudioSubscription $subscription;

    private ListingStudioBillingRequest $request;

    private SubscriptionActionsGateway $gateway;

    private StudioSubscriptionActionService $service;

    private int $start;

    private int $end;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-16T00:00:00Z'));
        $this->start = CarbonImmutable::parse('2026-11-01T00:00:00Z')->timestamp;
        $this->end = CarbonImmutable::parse('2026-12-01T00:00:00Z')->timestamp;
        config()->set(['listing-studio.subscription_controls_enabled' => true, 'listing-studio.delegated_enabled' => true, 'listing-studio.paid_enabled' => true,
            'listing-studio.paid_checkout_enabled' => true, 'listing-studio.stripe_enabled' => true,
            'listing-studio.stripe_mode' => 'test', 'listing-studio.stripe_account_id' => 'acct_controls']);
        foreach (['starter', 'pro', 'studio'] as $code) {
            config()->set('listing-studio.plans.'.$code.'.test_price', 'price_'.$code);
        }
        Http::preventStrayRequests();
        Queue::fake();
        $this->client = User::factory()->create(['role' => 'client', 'secondary_roles' => [], 'email' => 'owner@example.test']);
        $this->actor = User::factory()->create(['role' => 'superadmin']);
        $this->subscription = ListingStudioSubscription::create(['client_id' => $this->client->id,
            'stripe_account_id' => 'acct_controls', 'livemode' => false, 'stripe_customer_id' => 'cus_owner',
            'stripe_subscription_id' => 'sub_owner', 'stripe_price_id' => 'price_starter', 'plan_code' => 'starter', 'plan_name' => 'Starter',
            'status' => 'active', 'sync_status' => 'synced', 'amount_cents' => 4900, 'currency' => 'usd', 'billing_interval' => 'month',
            'billing_interval_count' => 1, 'current_period_start' => $this->iso($this->start), 'current_period_end' => $this->iso($this->end)]);
        $this->request = ListingStudioBillingRequest::create(['client_id' => $this->client->id, 'actor_id' => $this->actor->id,
            'subscription_id' => $this->subscription->id, 'kind' => 'subscription_upgrade', 'plan_code' => 'pro',
            'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'checkout_key' => (string) Str::uuid(),
            'status' => 'pending', 'expires_at' => now()->addDay()]);
        $initial = $this->invoice('in_initial', false);
        $this->gateway = new SubscriptionActionsGateway;
        $this->gateway->canonical = ['id' => 'sub_owner', 'livemode' => false, 'customer' => ['id' => 'cus_owner', 'email' => $this->client->email],
            'status' => 'active', 'currency' => 'usd', 'collection_method' => 'charge_automatically', 'billing_cycle_anchor' => $this->start,
            'cancel_at_period_end' => false, 'cancel_at' => null, 'pending_update' => null, 'schedule' => null,
            'items' => ['has_more' => false, 'data' => [['id' => 'si_owner', 'quantity' => 1, 'price' => $this->price('starter'),
                'current_period_start' => $this->start, 'current_period_end' => $this->end]]], 'latest_invoice' => $initial];
        $this->gateway->preview = $this->invoice('upcoming_preview', true, 'draft');
        $this->gateway->upgradeInvoice = $this->invoice('in_upgrade', true, 'open');
        $this->app->instance(StudioStripeGateway::class, $this->gateway);
        $this->service = app(StudioSubscriptionActionService::class);
        $sync = Mockery::mock(StripeSubscriptionGateway::class);
        $sync->shouldReceive('subscription')->andReturnUsing(fn () => $this->gateway->canonical);
        $sync->shouldReceive('invoice')->andReturnUsing(fn (string $id) => $id === 'in_upgrade' ? $this->gateway->upgradeInvoice : $initial);
        $this->app->instance(StripeSubscriptionGateway::class, $sync);
        app(ListingStudioCreditService::class)->reconcileInvoices($this->subscription, [$initial]);
    }

    public function test_half_period_review_is_canonical_noncharging_and_matches_existing_credit_math(): void
    {
        $result = $this->service->review($this->client, $this->request);
        $this->assertSame(2500, $result['review']['amountCents']);
        $this->assertSame(3750, $result['review']['additionalCreditCents']);
        $this->assertSame('starter', $this->subscription->fresh()->plan_code);
        $this->assertSame(6000, $this->balance());
        $this->assertSame([], $this->gateway->writes);
        $this->assertSame(now()->timestamp, $this->gateway->previewParameters['subscription_details']['proration_date']);
        $this->assertNotSame($result['review']['confirmationToken'], Action::sole()->confirmation_token_hash);
        $this->assertSame($this->actor->id, Action::sole()->actor_id);
        $this->assertSame($this->client->id, Action::sole()->client_id);
    }

    public function test_confirmation_requires_owner_explicit_consent_and_fresh_preview(): void
    {
        $review = $this->review();
        $this->expectCode('billing_request_not_found', fn () => $this->service->confirm(User::factory()->create(['role' => 'client']), $this->request, $review));
        $this->expectCode('subscription_review_expired', fn () => $this->service->confirm($this->client, $this->request, $review + ['acceptCharges' => false]));
        $this->travel(11)->minutes();
        $this->expectCode('subscription_review_expired', fn () => $this->service->confirm($this->client, $this->request, $review + ['acceptCharges' => true]));
        $this->assertSame([], $this->gateway->writes);
    }

    public function test_confirm_pins_preview_and_marker_and_does_not_grant_from_browser_response(): void
    {
        $review = $this->review();
        $this->gateway->payImmediately = true;
        $response = $this->confirm($review);
        $this->assertSame('processing', $response['status']);
        $this->assertSame('awaiting_webhook', Action::sole()->status);
        $this->assertSame('starter', $this->subscription->fresh()->plan_code);
        $this->assertSame(6000, $this->balance());
        $payload = $this->gateway->writes[0]['parameters'];
        $this->assertSame('pending_if_incomplete', $payload['payment_behavior']);
        $this->assertSame('always_invoice', $payload['proration_behavior']);
        $this->assertSame('unchanged', $payload['billing_cycle_anchor']);
        $this->assertSame(now()->timestamp, $payload['proration_date']);
        $this->confirm($review);
        $this->assertCount(1, $this->gateway->writes);
        $this->sync('invoice.paid', 'evt_paid');
        $this->assertSame('pro', $this->subscription->fresh()->plan_code);
        $this->assertSame(9750, $this->balance());
        $this->sync('customer.subscription.pending_update_applied', 'evt_applied');
        $this->assertSame(9750, $this->balance());
        $this->assertSame('completed', Action::sole()->status);
    }

    public function test_original_actor_must_still_be_eligible_superadmin_before_client_approval(): void
    {
        $review = $this->review();
        $this->actor->update(['role' => 'admin']);
        $this->expectCode('forbidden', fn () => $this->confirm($review));
        $this->actor->update(['role' => 'superadmin', 'locked_at' => now()]);
        $this->expectCode('forbidden', fn () => $this->confirm($review));
        $this->assertSame([], $this->gateway->writes);
        $this->assertNull(Action::sole()->submitted_at);
    }

    public function test_closed_delegated_gate_blocks_first_upgrade_post_but_preserves_accepted_recovery(): void
    {
        $review = $this->review();
        config()->set('listing-studio.delegated_enabled', false);
        $this->expectCode('subscription_controls_unavailable', fn () => $this->confirm($review));
        $this->assertSame([], $this->gateway->writes);
        config()->set('listing-studio.delegated_enabled', true);
        $this->confirm($review);
        config()->set('listing-studio.delegated_enabled', false);
        $this->assertSame('processing', $this->confirm($review)['status']);
        $this->assertCount(1, $this->gateway->writes);
    }

    public function test_paid_invoice_before_price_application_preserves_old_entitlements_until_second_event(): void
    {
        $this->confirm($this->review());
        $this->markPaid(false);
        $this->sync('invoice.paid', 'evt_paid_first');
        $this->assertSame('starter', $this->subscription->fresh()->plan_code);
        $this->assertSame(6000, $this->balance());
        $this->assertSame('awaiting_application', Action::sole()->status);
        $this->markPaid(true);
        $this->sync('customer.subscription.pending_update_applied', 'evt_applied_second');
        $this->assertSame('pro', $this->subscription->fresh()->plan_code);
        $this->assertSame(9750, $this->balance());
    }

    public function test_applied_price_before_paid_invoice_preserves_plan_and_credit_then_settles_once(): void
    {
        $this->confirm($this->review());
        $this->gateway->canonical['items']['data'][0]['price'] = $this->price('pro');
        $this->gateway->canonical['pending_update'] = null;
        $this->sync('customer.subscription.updated', 'evt_applied_first');
        $this->assertSame('starter', $this->subscription->fresh()->plan_code);
        $this->assertSame(6000, $this->balance());
        $this->markPaid(true);
        $this->sync('invoice.paid', 'evt_paid_second');
        $this->sync('invoice.paid', 'evt_paid_duplicate');
        $this->assertSame(9750, $this->balance());
        $this->assertSame('pro', $this->subscription->fresh()->plan_code);
        $this->assertSame(3, DB::table('listing_studio_credit_entries')->where('source_type', 'invoice')->count());
    }

    public function test_delayed_paid_upgrade_event_after_renewal_keeps_new_period_without_rollover(): void
    {
        $this->confirm($this->review());
        $this->markPaid(true);
        $this->advanceToPaidRenewal();
        app(StripeSubscriptionSync::class)->handle(['id' => 'evt_delayed_upgrade', 'type' => 'invoice.paid', 'livemode' => false,
            'data' => ['object' => $this->gateway->upgradeInvoice]]);
        $this->assertSame('completed', Action::sole()->status);
        $this->assertSame('pro', $this->subscription->fresh()->plan_code);
        $this->assertSame('2027-01-01', $this->subscription->fresh()->current_period_end->toDateString());
        $this->assertSame(13500, $this->balance());
        $this->assertSame(3750, (int) DB::table('listing_studio_credit_entries')->where('stripe_invoice_id', 'in_upgrade')->sum('amount_cents'));
    }

    public function test_subscription_event_after_renewal_fetches_known_upgrade_invoice_outside_transaction(): void
    {
        $this->confirm($this->review());
        $this->markPaid(true);
        $this->advanceToPaidRenewal();
        $this->sync('customer.subscription.updated', 'evt_renewal_after_upgrade');
        $this->assertSame('completed', Action::sole()->status);
        $this->assertSame(13500, $this->balance());
        $this->assertSame('2027-01-01', $this->subscription->fresh()->current_period_end->toDateString());
    }

    public function test_unidentified_old_upgrade_never_rolls_back_separately_paid_renewal(): void
    {
        $this->confirm($this->review());
        Action::sole()->update(['invoice_id' => null, 'status' => 'needs_attention']);
        $this->markPaid(true);
        $this->advanceToPaidRenewal();
        $this->sync('customer.subscription.updated', 'evt_new_paid_period');
        $this->assertSame('needs_attention', Action::sole()->status);
        $this->assertSame('pro', $this->subscription->fresh()->plan_code);
        $this->assertSame('2027-01-01', $this->subscription->fresh()->current_period_end->toDateString());
        $this->assertSame(13500, $this->balance());
        $this->assertSame(0, DB::table('listing_studio_credit_entries')->where('stripe_invoice_id', 'in_upgrade')->count());
    }

    public function test_timeout_keeps_marker_and_get_only_recovery_after_request_expiry_and_kill_switch(): void
    {
        $review = $this->review();
        $this->gateway->loseUpgradeResponse = true;
        $this->assertSame('needs_attention', $this->confirm($review)['status']);
        $this->assertNotNull(Action::sole()->submitted_at);
        $this->request->update(['expires_at' => now()->subMinute(), 'status' => 'needs_attention']);
        config()->set('listing-studio.subscription_controls_enabled', false);
        $recovery = $this->service->review($this->client, $this->request->fresh());
        $this->assertSame('processing', $recovery['status']);
        $this->assertSame('redirect', $recovery['nextAction']['type']);
        $this->assertStringStartsWith('https://invoice.stripe.com/', $recovery['url']);
        $this->service->review($this->client, $this->request->fresh());
        $this->assertCount(1, $this->gateway->writes);
    }

    public function test_voided_pending_invoice_expires_without_new_grant_or_resubmission(): void
    {
        $review = $this->review();
        $this->confirm($review);
        $this->gateway->canonical['latest_invoice']['status'] = 'void';
        $this->gateway->canonical['pending_update'] = null;
        $this->sync('customer.subscription.pending_update_expired', 'evt_expired');
        $this->assertSame('expired', Action::sole()->status);
        $this->assertSame(6000, $this->balance());
        $this->assertSame('expired', $this->confirm($review)['status']);
        $this->assertCount(1, $this->gateway->writes);
    }

    public function test_changed_amount_or_subscription_refuses_confirmation_without_calling_update(): void
    {
        $review = $this->review();
        $this->gateway->preview['amount_due'] = 2600;
        $this->expectCode('subscription_review_changed', fn () => $this->confirm($review));
        $this->gateway->canonical['pending_update'] = ['expires_at' => now()->addHour()->timestamp];
        $this->expectCode('subscription_conflict', fn () => $this->confirm($review));
        $this->assertSame([], $this->gateway->writes);
    }

    public function test_foreign_schedule_and_wrong_mode_or_customer_are_never_overwritten(): void
    {
        $this->gateway->canonical['schedule'] = 'sub_sched_foreign';
        $this->expectCode('subscription_conflict', fn () => $this->service->execute($this->actor, $this->client, 'cancel_renewal', '', (string) Str::uuid()));
        $this->gateway->canonical['schedule'] = null;
        $this->gateway->canonical['customer']['id'] = 'cus_other';
        $this->expectCode('billing_attention', fn () => $this->service->review($this->client, $this->request));
        $this->gateway->canonical['customer']['id'] = 'cus_owner';
        $this->gateway->canonical['livemode'] = true;
        $this->expectCode('billing_attention', fn () => $this->service->review($this->client, $this->request));
        $this->assertSame([], $this->gateway->writes);
    }

    public function test_cancel_and_undo_are_superadmin_only_end_of_period_and_do_not_invoice(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $this->expectCode('forbidden', fn () => $this->service->execute($rep, $this->client, 'cancel_renewal', '', (string) Str::uuid()));
        $key = (string) Str::uuid();
        $cancel = $this->service->execute($this->actor, $this->client, 'cancel_renewal', '', $key);
        $this->assertSame('scheduled', $cancel['action']['status']);
        $this->assertNull($cancel['url']);
        $this->assertTrue($this->gateway->writes[0]['parameters']['cancel_at_period_end']);
        $this->assertArrayNotHasKey('proration_behavior', $this->gateway->writes[0]['parameters']);
        $this->service->execute($this->actor, $this->client, 'cancel_renewal', '', $key);
        $this->assertCount(1, $this->gateway->writes);
        $undo = $this->service->execute($this->actor, $this->client, 'undo_cancel', '', (string) Str::uuid());
        $this->assertSame('completed', $undo['action']['status']);
        $this->assertFalse($this->gateway->writes[1]['parameters']['cancel_at_period_end']);
        $this->assertSame('cancelled', Action::find($cancel['action']['id'])->status);
        $this->assertSame(6000, $this->balance());
    }

    public function test_downgrade_retains_current_plan_and_schedules_then_releases_only_own_schedule(): void
    {
        $this->setPro();
        $down = $this->service->execute($this->actor, $this->client, 'schedule_downgrade', 'starter', (string) Str::uuid());
        $this->assertSame('scheduled', $down['action']['status']);
        $this->assertSame('pro', $this->subscription->fresh()->plan_code);
        $parameters = $this->gateway->writes[1]['parameters'];
        $this->assertSame('release', $parameters['end_behavior']);
        $this->assertSame('none', $parameters['proration_behavior']);
        $this->assertSame($this->end, $parameters['phases'][0]['end_date']);
        $this->assertSame('price_pro', $parameters['phases'][0]['items'][0]['price']);
        $this->assertSame('price_starter', $parameters['phases'][1]['items'][0]['price']);
        $this->assertSame($this->end, $parameters['phases'][1]['start_date']);
        $this->assertTrue($this->service->summary($this->client)['capabilities']['canUndoDowngrade']);
        $undo = $this->service->execute($this->actor, $this->client, 'undo_downgrade', '', (string) Str::uuid());
        $this->assertSame('completed', $undo['action']['status']);
        $this->assertSame('release_schedule', $this->gateway->writes[2]['method']);
        $this->assertSame('pro', $this->subscription->fresh()->plan_code);
        $this->assertSame('cancelled', Action::find($down['action']['id'])->status);
    }

    public function test_lost_schedule_create_never_creates_second_schedule_or_changes_unidentified_schedule(): void
    {
        $this->setPro();
        $this->gateway->loseScheduleResponse = true;
        $key = (string) Str::uuid();
        $first = $this->service->execute($this->actor, $this->client, 'schedule_downgrade', 'starter', $key);
        $this->assertSame('needs_attention', $first['status']);
        $this->service->execute($this->actor, $this->client, 'schedule_downgrade', 'starter', $key);
        $this->assertCount(1, $this->gateway->writes);
        $this->assertNull(Action::sole()->schedule_id);
        $this->expectCode('subscription_conflict', fn () => $this->service->execute($this->actor, $this->client, 'cancel_renewal', '', (string) Str::uuid()));
    }

    public function test_lost_schedule_configuration_response_recovers_existing_schedule_without_post(): void
    {
        $this->setPro();
        $this->gateway->loseConfigurationResponse = true;
        $key = (string) Str::uuid();
        $this->assertSame('needs_attention', $this->service->execute($this->actor, $this->client, 'schedule_downgrade', 'starter', $key)['status']);
        $this->assertSame('completed', $this->service->execute($this->actor, $this->client, 'schedule_downgrade', 'starter', $key)['status']);
        $this->assertCount(2, $this->gateway->writes);
    }

    public function test_expired_boundary_refuses_undo_and_readonly_summary_survives_closed_flag(): void
    {
        $cancel = $this->service->execute($this->actor, $this->client, 'cancel_renewal', '', (string) Str::uuid());
        config()->set('listing-studio.subscription_controls_enabled', false);
        $summary = $this->service->summary($this->client);
        $this->assertSame($cancel['action']['id'], $summary['pendingChange']['id']);
        $this->assertFalse($summary['capabilities']['canUndoCancel']);
        $this->expectCode('subscription_controls_unavailable', fn () => $this->service->execute($this->actor, $this->client, 'undo_cancel', '', (string) Str::uuid()));
        config()->set('listing-studio.subscription_controls_enabled', true);
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($this->end));
        $this->expectCode('billing_attention', fn () => $this->service->execute($this->actor, $this->client, 'undo_cancel', '', (string) Str::uuid()));
        $this->assertCount(1, $this->gateway->writes);
    }

    private function review(): array
    {
        return $this->service->review($this->client, $this->request)['review'];
    }

    private function confirm(array $review): array
    {
        return $this->service->confirm($this->client, $this->request, $review + ['acceptCharges' => true]);
    }

    private function sync(string $type, string $id): void
    {
        $object = str_starts_with($type, 'invoice.') ? $this->gateway->canonical['latest_invoice'] : $this->gateway->canonical;
        $result = app(StripeSubscriptionSync::class)->handle(['id' => $id, 'type' => $type, 'livemode' => false, 'data' => ['object' => $object]]);
        $this->assertSame('listing_studio_synced', $result['outcome']);
    }

    private function markPaid(bool $apply): void
    {
        $this->gateway->upgradeInvoice['status'] = 'paid';
        $this->gateway->upgradeInvoice['amount_paid'] = 2500;
        $this->gateway->upgradeInvoice['amount_remaining'] = 0;
        $this->gateway->canonical['latest_invoice'] = $this->gateway->upgradeInvoice;
        if ($apply) {
            $this->gateway->canonical['items']['data'][0]['price'] = $this->price('pro');
            $this->gateway->canonical['pending_update'] = null;
        }
    }

    private function setPro(): void
    {
        $this->subscription->update(['plan_code' => 'pro', 'stripe_price_id' => 'price_pro', 'amount_cents' => 9900]);
        $this->gateway->canonical['items']['data'][0]['price'] = $this->price('pro');
    }

    private function advanceToPaidRenewal(): void
    {
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($this->end)->addDay());
        $nextEnd = CarbonImmutable::parse('2027-01-01T00:00:00Z')->timestamp;
        $this->gateway->canonical['items']['data'][0]['current_period_start'] = $this->end;
        $this->gateway->canonical['items']['data'][0]['current_period_end'] = $nextEnd;
        $line = $this->line('il_renewal', 'pro', 9900, false);
        $line['period'] = ['start' => $this->end, 'end' => $nextEnd];
        $invoice = $this->invoice('in_renewal', false);
        $invoice['lines']['data'] = [$line];
        $invoice['amount_due'] = $invoice['amount_paid'] = $invoice['total'] = 9900;
        $invoice['billing_reason'] = 'subscription_cycle';
        $this->gateway->canonical['latest_invoice'] = $invoice;
    }

    private function invoice(string $id, bool $proration, string $status = 'paid'): array
    {
        $lines = $proration ? [$this->line('il_old', 'starter', -2450, true), $this->line('il_new', 'pro', 4950, true)]
            : [$this->line('il_initial', 'starter', 4900, false)];
        $amount = $proration ? 2500 : 4900;

        return ['id' => $id, 'livemode' => false, 'customer' => 'cus_owner', 'subscription' => 'sub_owner', 'currency' => 'usd',
            'billing_reason' => $proration ? 'subscription_update' : 'subscription_create', 'status' => $status,
            'amount_due' => $amount, 'amount_paid' => $status === 'paid' ? $amount : 0, 'amount_remaining' => $status === 'paid' ? 0 : $amount,
            'total' => $amount, 'created' => now()->timestamp, 'status_transitions' => ['paid_at' => now()->timestamp],
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/test_owned', 'lines' => ['data' => $lines, 'has_more' => false]];
    }

    private function line(string $id, string $plan, int $amount, bool $proration): array
    {
        return ['id' => $id, 'price' => 'price_'.$plan, 'quantity' => 1, 'amount' => $amount, 'type' => 'subscription',
            'subscription' => 'sub_owner', 'proration' => $proration, 'period' => ['start' => $proration ? now()->timestamp : $this->start, 'end' => $this->end]];
    }

    private function price(string $plan): array
    {
        return ['id' => 'price_'.$plan, 'active' => true, 'livemode' => false, 'currency' => 'usd',
            'unit_amount' => config('listing-studio.plans.'.$plan.'.price_cents'), 'recurring' => ['interval' => 'month', 'interval_count' => 1]];
    }

    private function balance(): int
    {
        return app(ListingStudioCreditService::class)->summary($this->subscription->fresh())['available_cents'];
    }

    private function iso(int $time): string
    {
        return CarbonImmutable::createFromTimestampUTC($time)->toIso8601String();
    }

    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected '.$code);
        } catch (StudioPaymentException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}

class SubscriptionActionsGateway extends StudioStripeGateway
{
    public array $canonical;

    public array $preview;

    public array $previewParameters = [];

    public array $upgradeInvoice;

    public array $scheduleData = [];

    public array $writes = [];

    public bool $payImmediately = false;

    public bool $loseUpgradeResponse = false;

    public bool $loseScheduleResponse = false;

    public bool $loseConfigurationResponse = false;

    public function account(): array
    {
        $this->outsideWrite();

        return ['id' => 'acct_controls'];
    }

    public function subscription(string $id): array
    {
        $this->outsideWrite();

        return $this->canonical;
    }

    public function invoice(string $id): array
    {
        $this->outsideWrite();

        return $this->upgradeInvoice;
    }

    public function price(string $id): array
    {
        $this->outsideWrite();

        return ['id' => $id, 'active' => true, 'livemode' => false, 'currency' => 'usd',
            'unit_amount' => config('listing-studio.plans.'.substr($id, 6).'.price_cents'), 'recurring' => ['interval' => 'month', 'interval_count' => 1]];
    }

    public function previewSubscription(array $parameters): array
    {
        $this->outsideWrite();
        $this->previewParameters = $parameters;

        return $this->preview;
    }

    public function updateSubscription(string $id, array $parameters, string $key): array
    {
        $this->record('subscription', $parameters, $key);
        if (isset($parameters['items'])) {
            $this->canonical['pending_update'] = ['expires_at' => now()->addHours(23)->timestamp, 'subscription_items' => $parameters['items']];
            $this->canonical['latest_invoice'] = $this->upgradeInvoice;
            if ($this->payImmediately) {
                $this->canonical['pending_update'] = null;
                $this->canonical['items']['data'][0]['price'] = $this->price('price_pro');
                $this->canonical['latest_invoice']['status'] = 'paid';
                $this->canonical['latest_invoice']['amount_paid'] = 2500;
                $this->canonical['latest_invoice']['amount_remaining'] = 0;
            }
            if ($this->loseUpgradeResponse) {
                throw new \RuntimeException('Lost response');
            }
        } else {
            $this->canonical['cancel_at_period_end'] = $parameters['cancel_at_period_end'];
        }

        return $this->canonical;
    }

    public function createSchedule(string $subscription, string $key): array
    {
        $this->record('create_schedule', ['from_subscription' => $subscription], $key);
        $item = $this->canonical['items']['data'][0];
        $this->scheduleData = ['id' => 'sub_sched_owned', 'livemode' => false, 'customer' => 'cus_owner', 'subscription' => 'sub_owner',
            'status' => 'active', 'current_phase' => ['start_date' => $item['current_period_start'], 'end_date' => $item['current_period_end']],
            'phases' => [['items' => [['price' => $item['price']['id'], 'quantity' => 1]]]]];
        $this->canonical['schedule'] = 'sub_sched_owned';
        if ($this->loseScheduleResponse) {
            throw new \RuntimeException('Lost schedule response');
        }

        return $this->scheduleData;
    }

    public function schedule(string $id): array
    {
        $this->outsideWrite();

        return $this->scheduleData;
    }

    public function updateSchedule(string $id, array $parameters, string $key): array
    {
        $this->record('configure_schedule', $parameters, $key);
        $this->scheduleData = array_replace($this->scheduleData, $parameters);
        if ($this->loseConfigurationResponse) {
            throw new \RuntimeException('Lost update response');
        }

        return $this->scheduleData;
    }

    public function releaseSchedule(string $id, string $key): array
    {
        $this->record('release_schedule', [], $key);
        $this->scheduleData['status'] = 'released';
        $this->scheduleData['subscription'] = null;
        $this->scheduleData['released_subscription'] = 'sub_owner';
        $this->canonical['schedule'] = null;

        return $this->scheduleData;
    }

    private function record(string $method, array $parameters, string $key): void
    {
        $this->outsideWrite();
        if (! Action::whereNotNull('submitted_at')->exists()) {
            throw new \RuntimeException('Missing durable submission marker');
        }
        $this->writes[] = compact('method', 'parameters', 'key');
    }

    private function outsideWrite(): void
    {
        // RefreshDatabase owns one outer test transaction; no nested financial write may surround I/O.
        if (DB::transactionLevel() > 1) {
            throw new \RuntimeException('Network inside write transaction');
        }
    }
}
