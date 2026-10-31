<?php

namespace Tests\Feature;

use App\Contracts\ListingStudio\ListingStudioOutcomeGuard;
use App\Contracts\ListingStudio\ListingStudioSourceResolver;
use App\Exceptions\PublicApiException;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderFundingEffect;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioSubscription;
use App\Models\User;
use App\Services\ApiErrorResponder;
use App\Services\ListingStudio\ListingStudioCatalogService;
use App\Services\ListingStudio\ListingStudioCreditService;
use App\Services\ListingStudio\ListingStudioOrderService;
use App\Services\ListingStudio\ListingStudioQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingStudioFinancialCoreTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private object $sources;

    private object $outcomes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        Http::preventStrayRequests();
        config(['listing-studio.paid_enabled' => true, 'listing-studio.paid_checkout_enabled' => true,
            'listing-studio.stripe_enabled' => true, 'listing-studio.human_revisions_enabled' => true,
            'listing-studio.paid_services' => array_fill_keys(['staging', 'decluttering', 'grass', 'twilight'], true)]);
        $this->client = User::factory()->create(['role' => 'client', 'account_status' => 'active']);
        // Financial unit boundary: real provider readiness is covered by processing integration tests.
        $this->app->instance(ListingStudioCatalogService::class, new class extends ListingStudioCatalogService
        {
            protected function aiReady(string $service): bool
            {
                return true;
            }
        });
        $this->sources = new class implements ListingStudioSourceResolver
        {
            public bool $rejectPin = false;

            public string $service = 'grass';

            public function resolve(User $client, string $kind, string $sourceKind, string $sourceId): array
            {
                $media = ['disk' => 'listing_studio_orders', 'path' => 'uploads/'.$sourceId.'/source.jpg',
                    'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg', 'width' => 1600, 'height' => 1000, 'byteSize' => 4000];

                return ['kind' => $sourceKind, 'id' => $sourceId, 'clientId' => $client->id,
                    'service' => $kind === 'human_revision' ? $this->service : null, 'expiresAt' => now()->addDay()->toIso8601String(),
                    ...$media, 'original' => $media, 'preview' => $media];
            }

            public function pin(array $snapshot, string $itemId): void
            {
                if ($this->rejectPin) {
                    throw new PublicApiException('Source expired.', 'source_unavailable', 422);
                }
            }
        };
        $this->outcomes = new class implements ListingStudioOutcomeGuard
        {
            public bool $unresolved = false;

            public function assertDefinitiveStop(ListingStudioOrderItem $item): void
            {
                if ($this->unresolved) {
                    throw new PublicApiException('Outcome unresolved.', 'revision_state_conflict', 409);
                }
            }

            public function assertDeliverableOutput(ListingStudioOrderItem $item, array $descriptor): void {}
        };
        $this->app->instance(ListingStudioSourceResolver::class, $this->sources);
        $this->app->instance(ListingStudioOutcomeGuard::class, $this->outcomes);
    }

    public function test_reservation_prevents_overspending_and_exact_replay_survives_quote_expiry_and_closed_gate(): void
    {
        $subscription = $this->subscription(700);
        $quote = $this->quote();
        $otherQuote = $this->quote();
        $key = (string) Str::uuid();
        $first = $this->orders()->acceptQuote($this->client, $quote->id, 'credits', $key);
        $this->assertTrue($first['created']);
        $this->assertSame(0, app(ListingStudioCreditService::class)->summary($subscription)['available_cents']);
        $this->expectCode('insufficient_credits', fn () => $this->orders()->acceptQuote($this->client, $otherQuote->id, 'credits', (string) Str::uuid()));
        $this->assertDatabaseCount('listing_studio_orders', 1);
        $this->assertDatabaseCount('listing_studio_credit_reservations', 1);
        $this->assertNull($otherQuote->fresh()->consumed_at);
        $this->travel(20)->minutes();
        config(['listing-studio.paid_enabled' => false]);
        $replay = $this->orders()->acceptQuote($this->client, $quote->id, 'credits', $key);
        $this->assertFalse($replay['created']);
        $this->assertSame($first['order']->id, $replay['order']->id);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $this->expectCode('idempotency_conflict', fn () => $this->orders()->acceptQuote($this->client, $otherQuote->id, 'credits', $key));
        Http::assertNothingSent();
    }

    public function test_source_pin_failure_rolls_back_order_credit_and_outbox(): void
    {
        $subscription = $this->subscription(1500);
        $quote = $this->quote();
        $this->sources->rejectPin = true;
        $this->expectCode('source_unavailable', fn () => $this->orders()->acceptQuote($this->client, $quote->id, 'credits', (string) Str::uuid()));
        $this->assertDatabaseCount('listing_studio_orders', 0);
        $this->assertDatabaseCount('listing_studio_order_items', 0);
        $this->assertDatabaseCount('listing_studio_credit_reservations', 0);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $this->assertSame(1500, app(ListingStudioCreditService::class)->summary($subscription)['available_cents']);
    }

    public function test_revision_quote_uses_owned_source_service_and_server_discount_snapshot(): void
    {
        $this->subscription(13500, 'pro');
        $quote = $this->quote('human_revision');
        $this->assertSame(1200, $quote->pricing_snapshot['totalPriceCents']);
        $this->assertSame(20, $quote->pricing_snapshot['discountPercent']);
        $this->assertSame(1200, $quote->pricing_snapshot['creditCostCents']);
        $this->sources->service = 'staging';
        $this->expectCode('source_unavailable', fn () => $this->quote('human_revision'));
        config(['listing-studio.plans.pro.revision_discount_percent' => 30]);
        $this->expectCode('quote_stale', fn () => $this->orders()->acceptQuote($this->client, $quote->id, 'credits', (string) Str::uuid()));
    }

    public function test_completion_captures_once_in_original_period_after_renewal_without_rollover(): void
    {
        $subscription = $this->subscription(1500);
        $oldEnd = $subscription->current_period_end->copy();
        $order = $this->accepted('human_revision');
        $item = $order->item;
        $begun = $this->orders()->beginItem($item->id, $item->processing_attempt_id);
        $this->assertNotNull($begun);
        $subscription->update(['current_period_start' => $oldEnd, 'current_period_end' => $oldEnd->copy()->addMonth(), 'plan_code' => 'pro']);
        $this->travelTo($oldEnd->copy()->addHour());
        $this->grant($subscription, 13500, 'in_next');
        $this->assertSame(13500, app(ListingStudioCreditService::class)->summary($subscription)['available_cents']);
        $descriptor = $this->delivery($item);
        $this->orders()->completeItem($item->id, $descriptor);
        $this->orders()->completeItem($item->id, $descriptor);
        $this->assertSame('captured', $order->reservation()->first()->status);
        $this->assertSame(1, DB::table('listing_studio_credit_entries')->where('source_type', 'usage')->count());
        $this->assertSame($oldEnd->format('Y-m-d H:i:s'), DB::table('listing_studio_credit_entries')->where('source_type', 'usage')->value('expires_at'));
        $this->assertSame(13500, app(ListingStudioCreditService::class)->summary($subscription)['available_cents']);
        $this->expectCode('revision_state_conflict', fn () => $this->orders()->cancelItem($item->id, 'order_cancelled'));
        $this->assertSame('completed', $item->fresh()->status);
    }

    public function test_held_outcome_blocks_refund_or_release_until_definitive_and_old_lease_cannot_clear_new_lease(): void
    {
        $subscription = $this->subscription(1500);
        $order = $this->accepted('human_revision');
        $item = $order->item;
        $first = $this->orders()->beginItem($item->id, $item->processing_attempt_id);
        $this->assertNull($this->orders()->beginItem($item->id, $item->processing_attempt_id));
        $this->orders()->holdItem($item->id, 'output_unavailable');
        $this->outcomes->unresolved = true;
        $this->expectCode('revision_state_conflict', fn () => $this->orders()->failItem($item->id, 'processing_failed'));
        $this->assertSame('reserved', $order->reservation()->first()->status);
        $this->travel(6)->minutes();
        $second = $this->orders()->beginItem($item->id, $item->processing_attempt_id);
        $this->assertNotSame($first->processing_lease_token, $second->processing_lease_token);
        $this->orders()->releaseProcessingLease($item->id, $first->processing_lease_token);
        $this->assertSame($second->processing_lease_token, $item->fresh()->processing_lease_token);
        $this->outcomes->unresolved = false;
        $this->orders()->failItem($item->id, 'processing_failed');
        $this->orders()->failItem($item->id, 'processing_failed');
        $this->assertSame(1500, app(ListingStudioCreditService::class)->summary($subscription)['available_cents']);
        $this->assertSame(0, DB::table('listing_studio_credit_entries')->where('source_type', 'usage')->count());
    }

    public function test_real_event_identity_settles_card_once_and_refund_statuses_do_not_double_count_or_undo_item(): void
    {
        $order = $this->accepted('ai_edit', 'card');
        $this->assertSame('awaiting_funding', $order->item->status);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $evidence = $this->paymentEvidence($order);
        $this->expectCode('payment_evidence_invalid', fn () => $this->orders()->confirmPayment($order->id, [...$evidence, 'eventId' => 'browser_lookup']));
        $this->assertSame(0, $order->fresh()->paid_amount_cents);
        $this->orders()->confirmPayment($order->id, $evidence);
        $this->orders()->confirmPayment($order->id, [...$evidence, 'eventId' => 'evt_distinct']);
        $this->assertDatabaseCount('listing_studio_order_funding_effects', 1);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $this->orders()->failItem($order->item->id, 'processing_failed');
        $this->assertSame('refund_pending', $order->fresh()->funding_status);
        $refund = ['provider' => 'stripe', 'accountId' => $evidence['accountId'], 'liveMode' => true,
            'refundAttemptId' => (string) Str::uuid(), 'refundOperationId' => (string) Str::uuid(), 'paymentIntentId' => $evidence['paymentIntentId'],
            'refundId' => 're_test', 'currency' => 'usd', 'amountCents' => 700, 'status' => 'pending', 'occurredAt' => now()->toIso8601String()];
        $this->orders()->confirmRefund($order->id, $refund);
        $this->assertSame(0, $order->fresh()->refunded_amount_cents);
        $this->orders()->confirmRefund($order->id, [...$refund, 'status' => 'failed']);
        $this->assertSame('settled', $order->fresh()->funding_status);
        ListingStudioOutbox::where('type', 'refund_order')->update(['status' => 'dispatched']);
        $this->orders()->confirmPayment($order->id, [...$evidence, 'eventId' => 'evt_after_failed_refund']);
        $this->assertSame('settled', $order->fresh()->funding_status);
        $this->assertSame('dispatched', ListingStudioOutbox::where('type', 'refund_order')->value('status'));
        $this->orders()->confirmRefund($order->id, [...$refund, 'status' => 'succeeded']);
        $this->orders()->confirmRefund($order->id, [...$refund, 'status' => 'succeeded', 'eventId' => 'evt_refund_repeat']);
        $this->orders()->confirmRefund($order->id, [...$refund, 'status' => 'pending']);
        $this->assertSame(700, $order->fresh()->refunded_amount_cents);
        $this->assertSame('refunded', $order->fresh()->funding_status);
        $this->assertSame('failed', $order->item()->first()->status);
        $this->assertSame(2, ListingStudioOrderFundingEffect::count());
        $this->assertSame(2, ListingStudioOutbox::count());
        Http::assertNothingSent();
    }

    public function test_paid_errors_nest_reviewed_details_but_existing_routes_keep_their_shape(): void
    {
        foreach (['/api/listing-studio/client/orders', '/api/listing-studio/team/revisions', '/api/external/listing-studio/catalog'] as $path) {
            $payload = app(ApiErrorResponder::class)->render(new PublicApiException('Changed.', 'version_conflict', 409, ['currentVersion' => 4]), Request::create($path))->getData(true);
            $this->assertFalse($payload['success']);
            $this->assertSame(['currentVersion' => 4], $payload['data']);
            $this->assertArrayNotHasKey('currentVersion', $payload);
        }
        foreach (['/api/listing-studio/trials/claim', '/api/bookings'] as $path) {
            $payload = app(ApiErrorResponder::class)->render(new PublicApiException('Changed.', 'version_conflict', 409, ['currentVersion' => 4]), Request::create($path))->getData(true);
            $this->assertSame(4, $payload['currentVersion']);
            $this->assertArrayNotHasKey('success', $payload);
            $this->assertArrayNotHasKey('data', $payload);
        }
    }

    public function test_verified_payment_reconciles_all_refunds_atomically_before_dispatch_and_recovers_only_before_processing(): void
    {
        $order = $this->accepted('ai_edit', 'card');
        $evidence = $this->paymentEvidence($order);
        $this->expectCode('payment_evidence_invalid', fn () => $this->orders()->confirmPayment($order->id, array_diff_key($evidence, ['refundsVerified' => true])));
        $refund = ['provider' => 'stripe', 'accountId' => $evidence['accountId'], 'liveMode' => true,
            'refundAttemptId' => (string) Str::uuid(), 'refundOperationId' => (string) Str::uuid(), 'paymentIntentId' => $evidence['paymentIntentId'],
            'refundId' => 're_pre_payment', 'currency' => 'usd', 'amountCents' => 700, 'status' => 'pending', 'occurredAt' => now()->toIso8601String()];
        $this->orders()->confirmPayment($order->id, [...$evidence, 'refunds' => [$refund]]);
        $this->assertSame('refund_pending', $order->fresh()->funding_status);
        $this->assertSame('needs_attention', $order->item()->first()->status);
        $this->assertDatabaseCount('listing_studio_outbox', 0);
        $this->orders()->confirmRefund($order->id, [...$refund, 'status' => 'failed']);
        $this->assertSame('settled', $order->fresh()->funding_status);
        $this->assertSame('queued', $order->item()->first()->status);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $this->orders()->confirmRefund($order->id, $refund);
        $this->assertSame('settled', $order->fresh()->funding_status);
        $event = ListingStudioOutbox::firstOrFail();
        $event->update(['status' => 'dispatched']);
        $this->travel(1)->seconds();
        $pending = [...$refund, 'refundId' => 're_second_pending', 'refundAttemptId' => (string) Str::uuid(),
            'refundOperationId' => (string) Str::uuid(), 'status' => 'pending', 'occurredAt' => now()->toIso8601String()];
        $this->orders()->confirmRefund($order->id, $pending);
        $this->orders()->confirmRefund($order->id, [...$pending, 'status' => 'failed']);
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertDatabaseCount('listing_studio_outbox', 1);
        $item = $order->item()->first();
        $this->orders()->beginItem($item->id, $item->processing_attempt_id);
        $startedState = $item->fresh()->getRawOriginal();
        $pending = [...$pending, 'refundId' => 're_after_started', 'refundAttemptId' => (string) Str::uuid(), 'refundOperationId' => (string) Str::uuid()];
        $this->orders()->confirmRefund($order->id, $pending);
        $this->orders()->confirmRefund($order->id, [...$pending, 'status' => 'failed']);
        $this->assertSame($startedState, $item->fresh()->getRawOriginal());
        $this->orders()->confirmRefund($order->id, [...$pending, 'status' => 'succeeded']);
        $this->orders()->confirmPayment($order->id, [...$evidence, 'eventId' => 'evt_late', 'refunds' => [[...$pending, 'status' => 'succeeded']]]);
        $this->assertSame('refunded', $order->fresh()->funding_status);
        $this->assertSame(700, $order->fresh()->refunded_amount_cents);
        $this->assertSame($startedState, $item->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_unpaid_limit_preserves_replay_and_cancel_exit_but_refuses_unresolved_checkout(): void
    {
        config(['listing-studio.max_unpaid_orders_per_client' => 1]);
        $quote = $this->quote();
        $key = (string) Str::uuid();
        $order = $this->orders()->acceptQuote($this->client, $quote->id, 'card', $key)['order'];
        $next = $this->quote();
        $this->expectCode('unpaid_order_limit', fn () => $this->orders()->acceptQuote($this->client, $next->id, 'card', (string) Str::uuid()));
        $this->assertFalse($this->orders()->acceptQuote($this->client, $quote->id, 'card', $key)['created']);
        $this->assertNull($next->fresh()->consumed_at);
        $this->assertTrue($this->orders()->present($order)['actions']['canCancel']);
        $attempt = \App\Models\ListingStudioCheckoutAttempt::create(['client_id' => $this->client->id, 'order_id' => $order->id,
            'kind' => 'order', 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'account_id' => config('listing-studio.stripe_account_id'), 'livemode' => true, 'currency' => 'usd', 'amount_cents' => 700, 'status' => 'pending']);
        foreach (['pending', 'submitting', 'open', 'awaiting_reconciliation', 'awaiting_payment', 'awaiting_webhook', 'paid'] as $status) {
            $attempt->update(['status' => $status]);
            $this->assertFalse($this->orders()->present($order->fresh())['actions']['canCancel']);
            $this->expectCode('order_cancellation_unavailable', fn () => $this->orders()->cancelUnpaidOrder($this->client, $order->id));
        }
        $attempt->update(['status' => 'expired']);
        $cancelled = $this->orders()->cancelUnpaidOrder($this->client, $order->id);
        $this->assertSame('cancelled', $cancelled->item->status);
        $this->assertSame('released', $cancelled->funding_status);
        $this->assertSame($order->id, $this->orders()->cancelUnpaidOrder($this->client, $order->id)->id);
        $this->assertSame('none', $this->orders()->nextAction($cancelled)['type']);
        $this->assertTrue($this->orders()->acceptQuote($this->client, $next->id, 'card', (string) Str::uuid())['created']);
        $this->assertDatabaseCount('listing_studio_orders', 2);
        Http::assertNothingSent();
    }

    public function test_account_and_history_are_private_paginated_and_billing_management_survives_purchase_shutdown(): void
    {
        $this->subscription(1500);
        $order = $this->accepted('human_revision');
        $this->orders()->beginItem($order->item->id, $order->item->processing_attempt_id);
        $this->orders()->completeItem($order->item->id, $this->delivery($order->item));
        $accounts = app(\App\Services\ListingStudio\ListingStudioAccountService::class);
        config(['listing-studio.paid_enabled' => false, 'listing-studio.paid_checkout_enabled' => false]);
        $account = $accounts->account($this->client);
        $this->assertTrue($account['capabilities']['canManageBilling']);
        $this->assertFalse($account['capabilities']['canSubscribe']);
        $this->assertSame(0, $account['credits']['availableCents']);
        $this->assertSame(1500, $account['credits']['usedCents']);
        $page = $accounts->transactions($this->client, 1, 1);
        $this->assertSame(2, $page['pagination']['total']);
        $this->assertTrue($page['pagination']['hasMore']);
        $this->assertSame('credit_usage', $page['transactions'][0]['kind']);
        $this->assertSame($order->id, $page['transactions'][0]['orderId']);
        $this->assertSame(-1500, $page['transactions'][0]['amountCents']);
        $json = json_encode($accounts->orders($this->client, []));
        $this->assertStringNotContainsString('sha256', $json);
        $this->assertStringNotContainsString('revision-deliveries/', $json);
        $other = User::factory()->create(['role' => 'client', 'account_status' => 'active']);
        $this->assertSame([], $accounts->orders($other, [])['orders']);
        $this->assertSame([], $accounts->transactions($other)['transactions']);
        $this->assertFalse($accounts->account($other)['capabilities']['canManageBilling']);
        \Laravel\Sanctum\Sanctum::actingAs($other);
        $this->getJson('/api/listing-studio/client/orders/'.$order->id)->assertNotFound();
        $this->postJson('/api/listing-studio/client/orders/'.$order->id.'/cancel')->assertNotFound();
        $this->getJson('/api/listing-studio/client/account')->assertOk()->assertJsonPath('success', true);
        Http::assertNothingSent();
    }

    public function test_paid_http_routes_keep_email_verification_and_reject_staff_and_impersonation(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/listing-studio/client/account')->assertForbidden();
        \Laravel\Sanctum\Sanctum::actingAs($this->client);
        $this->withHeader('X-Impersonate-User-Id', (string) $this->client->id)->getJson('/api/listing-studio/client/account')->assertForbidden();
        $this->flushHeaders();
        DB::table('auth_security_rollouts')->updateOrInsert(['name' => 'email-verification'], ['started_at' => now()->subDays(15)]);
        $this->client->forceFill(['email_verified_at' => null, 'email_verification_required_at' => now()->subDays(15)])->save();
        \Laravel\Sanctum\Sanctum::actingAs($this->client->fresh());
        $this->getJson('/api/listing-studio/client/account')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->getJson('/api/listing-studio/trials')->assertOk();
    }

    public function test_trial_usage_keeps_completed_expired_allowance_and_bridge_is_catalog_read_only(): void
    {
        $make = function (string $service, string $status, bool $completed, ?int $clientId) {
            return \App\Models\ListingStudioGuestTrial::create(['client_id' => $clientId, 'session_hash' => str_repeat('a', 64),
                'ip_hash' => str_repeat('b', 64), 'service' => $service, 'status' => $status, 'idempotency_key' => (string) Str::uuid(),
                'request_hash' => str_repeat('c', 64), 'source_path' => 'private-test/source.jpg', 'source_mime' => 'image/jpeg',
                'source_name' => 'source.jpg', 'started_at' => now()->subDays(8), 'expires_at' => now()->subDay(),
                'completed_at' => $completed ? now()->subDays(7) : null]);
        };
        $make('grass', 'expired', true, $this->client->id);
        $make('twilight', 'needs_attention', false, $this->client->id);
        $make('staging', 'failed', false, $this->client->id);
        $make('decluttering', 'completed', true, null);
        $usage = collect(app(\App\Services\ListingStudio\ListingStudioAccountService::class)->account($this->client)['trialUsage']['services'])->keyBy('service');
        $this->assertSame(['service' => 'grass', 'completed' => 1, 'reserved' => 0, 'remaining' => 0], $usage['grass']);
        $this->assertSame(1, $usage['twilight']['reserved']);
        $this->assertSame(1, $usage['staging']['remaining']);
        $this->assertSame(1, $usage['decluttering']['remaining']);
        config(['listing-studio-trials.bridge_key' => str_repeat('d', 40)]);
        config(['listing-studio.paid_enabled' => false, 'listing-studio.paid_checkout_enabled' => false]);
        $this->getJson('/api/external/listing-studio/catalog')->assertUnauthorized();
        $this->withHeaders(['X-Studio-Key' => str_repeat('d', 40)])
            ->getJson('/api/external/listing-studio/catalog')->assertOk()->assertJsonPath('data.services.2.aiPriceCents', 700)
            ->assertJsonPath('data.version', 'catalog-v1')->assertJsonPath('data.checkoutAvailable', false)->assertJsonPath('data.services.2.aiAvailable', false);
        $this->getJson('/api/external/listing-studio/capabilities')->assertUnprocessable()->assertJsonPath('code', 'studio_session_invalid');
        $this->postJson('/api/listing-studio/client/orders', [])->assertUnauthorized();
        $this->getJson('/api/listing-studio/client/account')->assertUnauthorized();
    }

    public function test_independent_sqlite_processes_cannot_both_spend_last_credit_or_take_last_unpaid_order_slot(): void
    {
        $this->subscription(700);
        $quotes = [$this->quote(), $this->quote()];
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Carbon::setTestNow('2026-10-31 12:00:00');
Carbon\CarbonImmutable::setTestNow('2026-10-31 12:00:00');
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$argv[2],
    'database.connections.sqlite.busy_timeout'=>5000,'logging.default'=>'null',
    'listing-studio.paid_enabled'=>true,'listing-studio.paid_checkout_enabled'=>true,'listing-studio.stripe_enabled'=>true,
    'listing-studio.paid_services.grass'=>true,'listing-studio.max_unpaid_orders_per_client'=>1]);
Illuminate\Support\Facades\DB::purge('sqlite');
$app->instance(App\Services\ListingStudio\ListingStudioCatalogService::class, new class extends App\Services\ListingStudio\ListingStudioCatalogService {
    protected function aiReady(string $service): bool { return true; }
});
$app->instance(App\Contracts\ListingStudio\ListingStudioSourceResolver::class, new class implements App\Contracts\ListingStudio\ListingStudioSourceResolver {
    public function resolve(App\Models\User $client,string $kind,string $sourceKind,string $sourceId): array { throw new LogicException('Only existing quotes.'); }
    public function pin(array $snapshot,string $itemId): void {}
});
$app->instance(App\Contracts\ListingStudio\ListingStudioOutcomeGuard::class, new class implements App\Contracts\ListingStudio\ListingStudioOutcomeGuard {
    public function assertDefinitiveStop(App\Models\ListingStudioOrderItem $item): void {}
    public function assertDeliverableOutput(App\Models\ListingStudioOrderItem $item,array $descriptor): void {}
});
// Both independent readers reach the first real order insert before either proceeds.
App\Models\ListingStudioOrder::creating(function () use ($argv) {
    static $waited=false;
    if ($waited) { return; }
    $waited=true;
    touch($argv[6].'/'.$argv[7]);
    $deadline=microtime(true)+10;
    while (count(glob($argv[6].'/*.ready'))<2) {
        if (microtime(true)>$deadline) { throw new RuntimeException('Race barrier timed out.'); }
        usleep(10000);
    }
});
try {
    app(App\Services\ListingStudio\ListingStudioOrderService::class)->acceptQuote(App\Models\User::findOrFail($argv[3]),$argv[4],$argv[5],(string)Illuminate\Support\Str::uuid());
    echo 'created';
} catch (App\Exceptions\PublicApiException $error) { echo $error->errorCode; }
PHP;
        foreach (['credits' => 'insufficient_credits', 'card' => 'unpaid_order_limit'] as $funding => $rejection) {
            $root = sys_get_temp_dir().'/studio-financial-race-'.bin2hex(random_bytes(8));
            mkdir($root, 0700);
            $file = $root.'/database.sqlite';
            $pdo = new \PDO('sqlite:'.$file);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $tables = ['users', 'listing_studio_subscriptions', 'listing_studio_credit_entries', 'listing_studio_quotes',
                'listing_studio_orders', 'listing_studio_order_items', 'listing_studio_credit_reservations', 'listing_studio_outbox', 'listing_studio_order_funding_effects'];
            foreach ($tables as $table) {
                $schema = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table]);
                $pdo->exec($schema->sql);
                foreach (DB::table($table)->get() as $row) {
                    $values = (array) $row;
                    $insert = $pdo->prepare('INSERT INTO "'.$table.'" ("'.implode('","', array_keys($values)).'") VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                    $insert->execute(array_values($values));
                }
            }
            foreach (DB::table('sqlite_master')->where('type', 'index')->whereIn('tbl_name', $tables)->whereNotNull('sql')->get() as $index) {
                $pdo->exec($index->sql);
            }
            try {
                $processes = [];
                foreach ($quotes as $i => $quote) {
                    $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script, base_path(), $file, (string) $this->client->id, $quote->id, $funding, $root, $i.'.ready']);
                    $process->setTimeout(90);
                    $process->start();
                    $processes[] = $process;
                }
                $outcomes = [];
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                    $outcomes[] = $process->getOutput();
                }
                sort($outcomes);
                $this->assertSame(['created', $rejection], $outcomes);
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_orders')->fetchColumn());
                $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_order_items')->fetchColumn());
                $this->assertSame($funding === 'credits' ? 1 : 0, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_credit_reservations')->fetchColumn());
                $this->assertSame($funding === 'credits' ? 1 : 0, (int) $pdo->query('SELECT COUNT(*) FROM listing_studio_outbox')->fetchColumn());
            } finally {
                foreach ($processes ?? [] as $process) {
                    if ($process->isRunning()) {
                        $process->stop();
                    }
                }
                $pdo = null;
                foreach (glob($root.'/*') as $temporary) {
                    unlink($temporary);
                }
                rmdir($root);
            }
        }
    }

    public function test_mid_period_upgrade_and_downgrade_preserve_reserved_price_and_reprice_only_new_orders(): void
    {
        foreach ([['starter', 'pro', 6000, -2450, 4950, 1500, 1200], ['pro', 'starter', 13500, -4950, 2450, 1200, 1500]] as [$oldPlan, $newPlan, $grant, $oldLine, $newLine, $oldCost, $newCost]) {
            $this->client = User::factory()->create(['role' => 'client', 'account_status' => 'active']);
            $subscription = $this->subscription($grant, $oldPlan);
            $order = $this->accepted('human_revision');
            $stale = $this->quote('human_revision');
            $reservation = $order->reservation()->first()->getRawOriginal();
            $line = fn ($id, $plan, $amount) => ['id' => $id, 'type' => 'subscription', 'quantity' => 1, 'amount' => $amount,
                'pricing' => ['price_details' => ['price' => config('listing-studio.plans.'.$plan.'.live_price')]],
                'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['subscription' => $subscription->stripe_subscription_id, 'proration' => true]],
                'period' => ['start' => $subscription->current_period_start->timestamp, 'end' => $subscription->current_period_end->timestamp]];
            $invoice = ['id' => 'in_change_'.$subscription->id, 'livemode' => true, 'status' => 'paid', 'currency' => 'usd',
                'amount_paid' => max(0, $oldLine + $newLine), 'customer' => $subscription->stripe_customer_id,
                'parent' => ['subscription_details' => ['subscription' => $subscription->stripe_subscription_id]], 'billing_reason' => 'subscription_update',
                'lines' => ['data' => [$line('il_old', $oldPlan, $oldLine), $line('il_new', $newPlan, $newLine)], 'has_more' => false]];
            $subscription->update(['plan_code' => $newPlan]);
            $credits = app(ListingStudioCreditService::class);
            DB::transaction(fn () => $credits->reconcileInvoices($subscription, [$invoice]));
            DB::transaction(fn () => $credits->reconcileInvoices($subscription, [$invoice]));
            $this->assertSame($reservation, $order->reservation()->first()->getRawOriginal());
            $this->assertSame($oldCost, $order->pricing_snapshot['totalPriceCents']);
            $this->assertSame($oldCost, $credits->summary($subscription)['reserved_cents']);
            $this->assertSame(9750 - $oldCost, $credits->summary($subscription)['available_cents']);
            $this->expectCode('quote_stale', fn () => $this->orders()->acceptQuote($this->client, $stale->id, 'credits', (string) Str::uuid()));
            $this->assertNull($stale->fresh()->consumed_at);
            $this->assertSame($newCost, $this->quote('human_revision')->pricing_snapshot['totalPriceCents']);
            $item = $order->item;
            $this->orders()->beginItem($item->id, $item->processing_attempt_id);
            $this->orders()->completeItem($item->id, $this->delivery($item));
            $this->orders()->completeItem($item->id, $this->delivery($item));
            $this->assertSame('captured', $order->reservation()->first()->status);
            $this->assertSame(9750 - $oldCost, $credits->summary($subscription)['available_cents']);
            $this->assertSame(-$oldCost, (int) DB::table('listing_studio_credit_entries')->where('subscription_id', $subscription->id)->where('source_type', 'usage')->sum('amount_cents'));
        }
        Http::assertNothingSent();
    }

    public function test_ambiguous_subscriptions_block_new_credit_and_card_spend_but_keep_exact_replay_and_billing_management(): void
    {
        $this->subscription(6000);
        $existingQuote = $this->quote();
        $key = (string) Str::uuid();
        $order = $this->orders()->acceptQuote($this->client, $existingQuote->id, 'card', $key)['order'];
        $unaccepted = $this->quote();
        $this->subscription(13500, 'pro');
        $this->expectCode('billing_attention', fn () => $this->quote());
        foreach (['credits', 'card'] as $funding) {
            $this->expectCode('billing_attention', fn () => $this->orders()->acceptQuote($this->client, $unaccepted->id, $funding, (string) Str::uuid()));
        }
        $quote = app(ListingStudioQuoteService::class)->present($unaccepted, $this->client);
        $this->assertFalse($quote['fundingOptions']['credits']['available']);
        $this->assertFalse($quote['fundingOptions']['card']['available']);
        $this->assertSame('billing_attention', $quote['fundingOptions']['card']['reason']);
        $replay = $this->orders()->acceptQuote($this->client, $existingQuote->id, 'card', $key);
        $this->assertFalse($replay['created']);
        $this->assertSame($order->id, $replay['order']->id);
        $this->assertFalse($this->orders()->present($order)['actions']['canCheckout']);
        $accounts = app(\App\Services\ListingStudio\ListingStudioAccountService::class);
        $account = $accounts->account($this->client);
        foreach (['canCreateOrder', 'canUseCredits', 'canPayByCard', 'canSubscribe'] as $capability) {
            $this->assertFalse($account['capabilities'][$capability]);
        }
        $this->assertTrue($account['capabilities']['canManageBilling']);
        $this->assertSame('billing_attention', $account['status']);
        $this->assertSame(1, $accounts->orders($this->client, [])['pagination']['total']);
        $this->assertNull($unaccepted->fresh()->consumed_at);
        $this->assertDatabaseCount('listing_studio_orders', 1);
        Http::assertNothingSent();
    }

    private function subscription(int $amount, string $plan = 'starter'): ListingStudioSubscription
    {
        $subscription = ListingStudioSubscription::create(['stripe_account_id' => config('listing-studio.stripe_account_id'), 'livemode' => true,
            'stripe_subscription_id' => 'sub_'.Str::random(12), 'stripe_customer_id' => 'cus_client', 'client_id' => $this->client->id,
            'plan_code' => $plan, 'plan_name' => ucfirst($plan), 'status' => 'active', 'sync_status' => 'synced', 'currency' => 'usd',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->addMonth()->startOfMonth()]);
        $this->grant($subscription, $amount, 'in_initial');

        return $subscription;
    }

    private function grant(ListingStudioSubscription $subscription, int $amount, string $invoice): void
    {
        DB::table('listing_studio_credit_entries')->insert(['subscription_id' => $subscription->id, 'stripe_invoice_id' => $invoice,
            'source_type' => 'invoice', 'source_key' => $invoice, 'plan_code' => $subscription->plan_code, 'amount_cents' => $amount,
            'currency' => 'usd', 'period_start' => $subscription->current_period_start, 'expires_at' => $subscription->current_period_end,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function quote(string $kind = 'ai_edit')
    {
        return app(ListingStudioQuoteService::class)->create($this->client, ['kind' => $kind, 'service' => 'grass',
            'source' => ['kind' => $kind === 'ai_edit' ? 'upload' : 'claimed_trial', 'id' => (string) Str::uuid()],
            ...($kind === 'human_revision' ? ['instructions' => 'Please refine the pathway edges.'] : [])]);
    }

    private function accepted(string $kind = 'ai_edit', string $funding = 'credits'): ListingStudioOrder
    {
        return $this->orders()->acceptQuote($this->client, $this->quote($kind)->id, $funding, (string) Str::uuid())['order'];
    }

    private function orders(): ListingStudioOrderService
    {
        return app(ListingStudioOrderService::class);
    }

    private function delivery(ListingStudioOrderItem $item): array
    {
        $media = ['disk' => 'listing_studio_orders', 'sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg',
            'width' => 1000, 'height' => 700, 'byteSize' => 4000];

        return ['itemId' => $item->id, 'clientId' => $item->client_id, ...$media,
            'path' => 'revision-deliveries/'.Str::uuid().'/result.jpg',
            'preview' => [...$media, 'path' => 'items/'.$item->id.'/result-preview.jpg']];
    }

    private function paymentEvidence(ListingStudioOrder $order): array
    {
        return ['provider' => 'stripe', 'eventId' => 'evt_payment', 'accountId' => config('listing-studio.stripe_account_id'), 'liveMode' => true,
            'checkoutAttemptId' => (string) Str::uuid(), 'checkoutSessionId' => 'cs_test', 'paymentIntentId' => 'pi_test', 'customerId' => 'cus_client',
            'currency' => 'usd', 'amountPaidCents' => $order->total_cents, 'paidAt' => now()->toIso8601String(), 'refundsVerified' => true, 'refunds' => []];
    }

    private function expectCode(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected reviewed error '.$code);
        } catch (PublicApiException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
