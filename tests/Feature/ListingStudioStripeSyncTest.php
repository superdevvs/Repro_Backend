<?php

namespace Tests\Feature;

use App\Jobs\SendListingStudioAccountSetup;
use App\Models\ClientEmailVerificationToken;
use App\Models\ListingStudioAccountSetup;
use App\Models\ListingStudioSubscription;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ListingStudio\StripeSubscriptionGateway;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\Users\AccountCreatedNotificationService;
use App\Services\Users\ClientEmailVerificationLinkService;
use App\Services\Users\EmailHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingStudioStripeSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $canonical;

    private bool $providerFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'listing-studio.stripe_enabled' => true, 'listing-studio.stripe_mode' => 'live',
            'listing-studio.stripe_account_id' => 'acct_15OuBBLsiebrQZS4',
            'listing-studio.webhook_secret' => 'whsec_studio_test',
            'services.stripe.webhook_secret' => 'whsec_dashboard_test',
            'services.stripe.secret_key' => null,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        $this->canonical = $this->subscription();
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $gateway->shouldReceive('subscription')->andReturnUsing(function () {
            if ($this->providerFails) {
                throw new \RuntimeException('Simulated unavailable provider.');
            }

            return $this->canonical;
        });
        $gateway->shouldReceive('invoice')->andReturnUsing(function (string $id) {
            if (($this->canonical['latest_invoice']['id'] ?? '') === $id) {
                return $this->canonical['latest_invoice'];
            }

            return array_replace($this->subscription()['latest_invoice'], ['id' => $id]);
        });
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
    }

    public function test_paid_signup_automatically_creates_one_unverified_client_and_durable_setup_without_shoot_payment(): void
    {
        $this->event()->assertOk()->assertJsonPath('outcome', 'listing_studio_synced');
        $client = User::where('email', 'buyer@example.test')->sole();
        $this->assertSame('client', $client->role);
        $this->assertTrue($client->password_reset_required);
        $this->assertNull($client->email_verified_at);
        $this->assertNotNull($client->email_verification_required_at);
        $this->assertNotEmpty($client->password);
        $this->assertDatabaseHas('listing_studio_subscriptions', ['client_id' => $client->id, 'plan_code' => 'starter', 'sync_status' => 'synced', 'amount_cents' => 4900]);
        $this->assertDatabaseHas('listing_studio_account_setups', ['client_id' => $client->id, 'status' => 'pending']);
        $this->assertDatabaseHas('listing_studio_credit_entries', ['stripe_invoice_id' => 'in_listing_test', 'amount_cents' => 6000]);
        Queue::assertPushed(SendListingStudioAccountSetup::class, 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stripe_checkout_attempts', 0);

        $this->event()->assertOk()->assertJsonPath('outcome', 'listing_studio_already_synced');
        $this->event('customer.subscription.updated', 'evt_second')->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('listing_studio_subscriptions', 1);
        $this->assertDatabaseCount('listing_studio_account_setups', 1);
        $this->assertDatabaseCount('listing_studio_stripe_events', 2);
        $this->assertSame(1, DB::table('listing_studio_credit_entries')->where('source_type', 'invoice')->count());
    }

    public function test_existing_client_matches_case_insensitive_email_without_any_account_mutation(): void
    {
        $client = User::factory()->create(['role' => 'client', 'secondary_roles' => [], 'email' => 'BUYER@EXAMPLE.TEST',
            'metadata' => ['stripe_customer_id_live' => 'cus_keep_existing'], 'name' => 'Keep profile']);
        $before = $client->fresh()->getRawOriginal();
        $this->event()->assertOk();
        $this->assertSame($before, $client->fresh()->getRawOriginal());
        $this->assertDatabaseHas('listing_studio_subscriptions', ['client_id' => $client->id, 'account_created' => false]);
        $this->assertDatabaseCount('listing_studio_account_setups', 0);
        Queue::assertNothingPushed();
    }

    public static function collisions(): array
    {
        return [['admin', [], false, false], ['client', ['admin'], false, false], ['client', [], true, false], ['client', [], false, true]];
    }

    #[DataProvider('collisions')]
    public function test_staff_secondary_role_locked_and_deleted_collisions_are_held_without_account_changes(string $role, array $secondary, bool $locked, bool $deleted): void
    {
        $user = User::factory()->create(['role' => $role, 'secondary_roles' => $secondary, 'email' => 'buyer@example.test', 'locked_at' => $locked ? now() : null]);
        if ($deleted) {
            $user->delete();
        }
        $before = $user->fresh()->getRawOriginal();
        $this->event()->assertOk();
        $this->assertSame($before, User::withTrashed()->find($user->id)->getRawOriginal());
        $this->assertDatabaseHas('listing_studio_subscriptions', ['client_id' => null, 'sync_status' => 'needs_attention']);
        $this->assertDatabaseCount('users', 1);
        Queue::assertNothingPushed();
    }

    public function test_missing_customer_email_and_case_duplicate_accounts_require_attention(): void
    {
        $this->canonical['customer']['email'] = null;
        $this->event()->assertOk();
        $this->assertDatabaseHas('listing_studio_subscriptions', ['client_id' => null, 'sync_status' => 'needs_attention']);
        $this->assertDatabaseCount('users', 0);
        $this->canonical['customer']['email'] = 'buyer@example.test';
        User::factory()->create(['email' => 'buyer@example.test', 'role' => 'client']);
        User::factory()->create(['email' => 'BUYER@example.test', 'role' => 'client']);
        $this->event('invoice.paid', 'evt_duplicate_email')->assertOk();
        $this->assertDatabaseHas('listing_studio_subscriptions', ['client_id' => null, 'sync_status' => 'needs_attention']);
    }

    public function test_unpaid_and_free_trial_do_not_create_accounts_but_async_paid_event_does(): void
    {
        $this->canonical['status'] = 'incomplete';
        $this->canonical['latest_invoice']['status'] = 'open';
        $this->canonical['latest_invoice']['amount_paid'] = 0;
        $this->event()->assertOk();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('listing_studio_subscriptions', ['sync_status' => 'awaiting_payment']);
        $this->canonical['status'] = 'trialing';
        $this->canonical['latest_invoice']['status'] = 'paid';
        $this->event('customer.subscription.updated', 'evt_trial')->assertOk();
        $this->assertDatabaseCount('users', 0);
        $this->canonical = $this->subscription();
        $this->event('checkout.session.async_payment_succeeded', 'evt_async_paid')->assertOk();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_out_of_order_paid_events_cannot_restore_a_canceled_or_past_due_canonical_subscription(): void
    {
        $this->event()->assertOk();
        $this->canonical['status'] = 'past_due';
        $this->canonical['latest_invoice']['id'] = 'in_renewal';
        $this->canonical['latest_invoice']['status'] = 'open';
        $this->canonical['latest_invoice']['amount_paid'] = 0;
        $this->event('invoice.payment_failed', 'evt_failed')->assertOk();
        $this->event('invoice.paid', 'evt_delayed_older_paid')->assertOk();
        $this->assertDatabaseHas('listing_studio_subscriptions', ['status' => 'past_due', 'latest_invoice_id' => 'in_renewal', 'latest_invoice_status' => 'open']);
        $this->canonical['status'] = 'canceled';
        $this->canonical['canceled_at'] = 1790550000;
        $this->event('customer.subscription.deleted', 'evt_cancel')->assertOk();
        $this->event('customer.subscription.updated', 'evt_delayed_active_snapshot')->assertOk();
        $this->assertDatabaseHas('listing_studio_subscriptions', ['status' => 'canceled']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('listing_studio_account_setups', 1);
    }

    public function test_paid_zero_dollar_promotional_invoice_creates_the_client_but_draft_does_not(): void
    {
        $this->canonical['latest_invoice']['amount_paid'] = 0;
        $this->canonical['latest_invoice']['status'] = 'draft';
        $this->event()->assertOk();
        $this->assertDatabaseCount('users', 0);
        $this->canonical['latest_invoice']['status'] = 'paid';
        $this->event('invoice.paid', 'evt_promotional_paid')->assertOk();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_account_setup_attention_is_visible_and_filterable_without_exposing_encrypted_links(): void
    {
        $this->event()->assertOk();
        ListingStudioAccountSetup::sole()->update(['status' => 'needs_attention', 'last_error' => 'Review account setup delivery.', 'notification_state' => ['links' => ['password_setup' => 'https://private.test/setup']]]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/listing-studio/subscriptions?status=needs_attention')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.account_setup_status', 'needs_attention')
            ->assertJsonPath('data.0.account_setup_attention', 'Review account setup delivery.')
            ->assertDontSee('private.test');
    }

    public function test_renewal_plan_and_item_period_changes_are_canonical_and_cancel_at_period_end_is_preserved(): void
    {
        $this->event()->assertOk();
        $this->canonical['items']['data'][0]['price']['id'] = 'price_1UKel0LsiebrQZS4jGdQ1odm';
        $this->canonical['items']['data'][0]['price']['unit_amount'] = 9900;
        $this->canonical['items']['data'][0]['current_period_end'] = 1793152000;
        $this->canonical['cancel_at_period_end'] = true;
        $this->canonical['latest_invoice']['status_transitions']['paid_at'] = 1790551000;
        $this->event('invoice.paid', 'evt_renewed')->assertOk();
        $row = ListingStudioSubscription::sole();
        $this->assertSame('pro', $row->plan_code);
        $this->assertSame(9900, $row->amount_cents);
        $this->assertSame(1793152000, $row->current_period_end->timestamp);
        $this->assertTrue($row->cancel_at_period_end);
        $this->assertSame(1790551000, $row->last_paid_at->timestamp);
    }

    public function test_wrong_price_mode_signature_account_and_mixed_items_cannot_create_enrollment(): void
    {
        $this->event(secret: 'wrong')->assertStatus(400);
        $this->event(livemode: false)->assertOk()->assertJsonPath('handled', false);
        $this->event(account: 'acct_other')->assertOk()->assertJsonPath('handled', false);
        $this->canonical['items']['data'][0]['price']['id'] = 'price_unrelated';
        $this->event()->assertOk()->assertJsonPath('handled', false);
        $this->canonical = $this->subscription();
        $this->canonical['items']['data'][] = $this->canonical['items']['data'][0];
        $this->event()->assertOk()->assertJsonPath('handled', false);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('listing_studio_subscriptions', 0);
    }

    public function test_existing_subscription_switched_to_unsupported_price_is_kept_for_attention(): void
    {
        $this->event()->assertOk();
        $this->canonical['items']['data'][0]['price']['id'] = 'price_other';
        $this->event('customer.subscription.updated', 'evt_unsupported_upgrade')->assertOk();
        $this->assertDatabaseHas('listing_studio_subscriptions', ['plan_code' => null, 'sync_status' => 'needs_attention']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_provider_failure_is_retryable_without_recording_an_event_or_account(): void
    {
        $this->providerFails = true;
        $this->event()->assertStatus(500)->assertJsonPath('status', 'retry');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('listing_studio_stripe_events', 0);
        $this->providerFails = false;
        $this->event()->assertOk();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_common_webhook_accepts_website_subscription_without_touching_shoot_payments(): void
    {
        $shoot = Shoot::factory()->create(['payment_status' => 'unpaid']);
        $before = $shoot->fresh()->getRawOriginal();
        $this->event(path: '/api/webhooks/stripe', secret: 'whsec_dashboard_test')->assertOk()->assertJsonPath('outcome', 'listing_studio_synced');
        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_staff_subscription_listing_is_scoped_paginated_and_client_forbidden(): void
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'secondary_roles' => [], 'email' => 'buyer@example.test', 'created_by_id' => $rep->id]);
        $this->event()->assertOk();
        Sanctum::actingAs($client);
        $this->getJson('/api/listing-studio/subscriptions')->assertForbidden();
        Sanctum::actingAs($rep);
        $this->getJson('/api/listing-studio/subscriptions?q=buyer&status=active')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.plan_name', 'Starter');
        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->getJson('/api/listing-studio/subscriptions')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['admin', 'superadmin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/listing-studio/subscriptions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.stripe_customer_id');
        }
    }

    public function test_queue_failure_after_commit_is_recoverable_without_duplicate_accounts(): void
    {
        $originalQueue = Queue::getFacadeRoot();
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->event()->assertStatus(500);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('listing_studio_stripe_events', 1);
        $this->assertDatabaseHas('listing_studio_account_setups', ['status' => 'pending']);
        Queue::swap($originalQueue);
        Queue::fake();
        $this->event()->assertOk()->assertJsonPath('outcome', 'listing_studio_already_synced');
        Queue::assertPushed(SendListingStudioAccountSetup::class, 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_setup_retry_preserves_links_and_successful_channels_then_duplicate_job_is_noop(): void
    {
        $this->event()->assertOk();
        $client = User::where('email', 'buyer@example.test')->sole();
        $setup = ListingStudioAccountSetup::sole();
        $token = ClientEmailVerificationToken::create([
            'user_id' => $client->id, 'email_snapshot' => $client->email, 'email_hash' => sha1($client->email),
            'token_hash' => hash('sha256', 'verification-test'), 'expires_at' => now()->addDay(),
        ]);
        $mail = Mockery::mock(MailService::class);
        $mail->shouldReceive('generateStoredPasswordResetLink')->once()->andReturn('https://dashboard.test/reset/stable');
        $mail->shouldReceive('sendAccountCreatedEmail')->twice()->with(Mockery::on(fn ($user) => $user instanceof User && $user->id === $client->id), 'https://dashboard.test/reset/stable', 'https://dashboard.test/verify/stable', null, 0, true)->andReturn(false, true);
        $mail->shouldReceive('sendClientEmailVerificationEmail')->once()->andReturn(true);
        $automation = Mockery::mock(AutomationService::class);
        $automation->shouldReceive('buildUserContext')->twice()->andReturn([]);
        $automation->shouldReceive('handleEvent')->twice()->andReturn(['email_sent_to' => []]);
        $automation->shouldReceive('shouldUseFallback')->andReturn(true);
        $links = Mockery::mock(ClientEmailVerificationLinkService::class);
        $links->shouldReceive('issueVerificationToken')->once()->andReturn($token);
        $links->shouldReceive('buildUrlForIssuedToken')->once()->andReturn('https://dashboard.test/verify/stable');
        $health = Mockery::mock(EmailHealthService::class);
        $health->shouldReceive('markVerificationSent')->once();
        $notifications = new AccountCreatedNotificationService(Mockery::mock(MessagingService::class), $mail, $automation, $links, $health);
        $job = new SendListingStudioAccountSetup($setup->id);
        try {
            $job->handle($notifications);
            $this->fail('A failed email must retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Listing Studio account setup delivery failed.', $exception->getMessage());
        }
        $this->assertSame('failed', $setup->fresh()->status);
        $this->assertStringNotContainsString('dashboard.test', DB::table('listing_studio_account_setups')->value('notification_state'));
        $job->handle($notifications);
        $job->handle($notifications);
        $this->assertSame('sent', $setup->fresh()->status);
        $this->assertSame(2, $setup->fresh()->attempts);
        $this->assertNull($setup->fresh()->notification_state);
    }

    public function test_disabled_account_created_automation_needs_attention_instead_of_claiming_sent(): void
    {
        $this->event()->assertOk();
        $setup = ListingStudioAccountSetup::sole();
        $notifications = Mockery::mock(AccountCreatedNotificationService::class);
        $notifications->shouldReceive('dispatch')->once()->andReturn([
            'email' => ['account_created' => ['attempted' => false, 'sent' => false], 'verification' => ['attempted' => true, 'sent' => true]],
            'sms' => ['attempted' => false, 'sent' => false],
        ]);
        (new SendListingStudioAccountSetup($setup->id))->handle($notifications);
        $this->assertSame('needs_attention', $setup->fresh()->status);
        $this->assertNull($setup->fresh()->sent_at);
    }

    public function test_recovery_requeues_safe_failures_and_flags_an_uncertain_worker_without_sending(): void
    {
        foreach (['pending', 'failed', 'processing'] as $status) {
            ListingStudioAccountSetup::create([
                'client_id' => User::factory()->create(['role' => 'client'])->id,
                'status' => $status, 'attempts' => 1, 'updated_at' => now()->subMinutes(10),
            ]);
        }
        $this->artisan('listing-studio:recover-account-setups')->assertExitCode(0);
        Queue::assertPushed(SendListingStudioAccountSetup::class, 2);
        $this->assertDatabaseHas('listing_studio_account_setups', ['status' => 'needs_attention']);
        $this->assertDatabaseMissing('listing_studio_account_setups', ['status' => 'sent']);
    }

    public function test_latest_refund_is_scoped_to_the_subscription_account_and_mode(): void
    {
        $this->event()->assertOk();
        $base = [
            'stripe_account_id' => 'acct_15OuBBLsiebrQZS4', 'livemode' => true,
            'stripe_subscription_id' => 'sub_listing_test', 'stripe_customer_id' => 'cus_listing_test',
            'stripe_invoice_id' => 'in_listing_test', 'stripe_payment_intent_id' => 'pi_listing_test',
            'stripe_charge_id' => 'ch_listing_test', 'amount_cents' => 1000, 'currency' => 'usd',
            'status' => 'succeeded', 'last_event_id' => 'evt_refund_fixture',
            'refund_created_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('listing_studio_subscription_refunds')->insert($base + ['stripe_refund_id' => 're_older']);
        DB::table('listing_studio_subscription_refunds')->insert(array_replace($base, [
            'stripe_refund_id' => 're_latest', 'refund_created_at' => now(), 'amount_cents' => 1500, 'status' => 'pending',
        ]));
        DB::table('listing_studio_subscription_refunds')->insert(array_replace($base, [
            'stripe_refund_id' => 're_different_account', 'stripe_account_id' => 'acct_other',
            'refund_created_at' => now()->addMinute(), 'amount_cents' => 9999,
        ]));
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/listing-studio/subscriptions')->assertOk()
            ->assertJsonPath('data.0.latest_refund.amount_cents', 1500)
            ->assertJsonPath('data.0.latest_refund.status', 'pending')
            ->assertJsonPath('data.0.status', 'active');
    }

    private function subscription(): array
    {
        return [
            'id' => 'sub_listing_test', 'object' => 'subscription', 'livemode' => true, 'status' => 'active',
            'customer' => ['id' => 'cus_listing_test', 'email' => 'buyer@example.test', 'name' => 'Listing Buyer'],
            'items' => ['data' => [[
                'quantity' => 1, 'current_period_start' => 1790550000, 'current_period_end' => 1793142000,
                'price' => ['id' => 'price_1UKekLLsiebrQZS44LVexrW5', 'unit_amount' => 4900, 'currency' => 'usd',
                    'recurring' => ['interval' => 'month', 'interval_count' => 1]],
            ]]],
            'cancel_at_period_end' => false,
            'latest_invoice' => ['id' => 'in_listing_test', 'status' => 'paid', 'amount_paid' => 4900, 'livemode' => true,
                'currency' => 'usd', 'billing_reason' => 'subscription_create',
                'customer' => 'cus_listing_test', 'parent' => ['subscription_details' => ['subscription' => 'sub_listing_test']],
                'lines' => ['has_more' => false, 'data' => [[
                    'id' => 'il_main', 'type' => 'subscription', 'quantity' => 1, 'amount' => 4900, 'subscription' => 'sub_listing_test',
                    'price' => ['id' => 'price_1UKekLLsiebrQZS44LVexrW5'],
                    'period' => ['start' => 1790550000, 'end' => 1793142000], 'proration' => false,
                ]]],
                'status_transitions' => ['paid_at' => 1790550000]],
        ];
    }

    private function event(string $type = 'checkout.session.completed', string $id = 'evt_listing_test', string $path = '/api/webhooks/stripe/listing-studio', string $secret = 'whsec_studio_test', bool $livemode = true, ?string $account = null)
    {
        $object = str_starts_with($type, 'customer.subscription.') ? ['id' => 'sub_listing_test']
            : (str_starts_with($type, 'invoice.') ? ['id' => 'in_listing_test', 'parent' => ['subscription_details' => ['subscription' => 'sub_listing_test']]]
                : ['id' => 'cs_listing_test', 'mode' => 'subscription', 'subscription' => 'sub_listing_test']);
        $event = ['id' => $id, 'object' => 'event', 'type' => $type, 'livemode' => $livemode, 'data' => ['object' => $object]];
        if ($account) {
            $event['account'] = $account;
        }
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
        ], $payload);
    }
}
