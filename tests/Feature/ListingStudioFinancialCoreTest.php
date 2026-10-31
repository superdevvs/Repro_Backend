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
            'currency' => 'usd', 'amountPaidCents' => $order->total_cents, 'paidAt' => now()->toIso8601String()];
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
