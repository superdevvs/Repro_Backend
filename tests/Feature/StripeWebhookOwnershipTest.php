<?php

namespace Tests\Feature;

use App\Http\Controllers\StripePaymentController;
use App\Logging\PrivacyLogProcessor;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\StripeCheckoutAttempt;
use App\Models\User;
use App\Services\Payments\StripeCheckoutOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StripeWebhookOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'services.stripe.webhook_secret' => 'whsec_local_ownership_test',
            'services.stripe.secret_key' => null,
            'services.stripe.app_instance' => 'reprodashboard-testing',
            'app.url' => 'https://api.reprodashboard.com',
            'app.frontend_url' => 'https://reprodashboard.com',
        ]);
        Queue::fake();
    }

    public static function checkoutEvents(): array
    {
        return array_map(fn ($type) => [$type], [
            'checkout.session.completed', 'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed', 'checkout.session.expired',
        ]);
    }

    #[DataProvider('checkoutEvents')]
    public function test_known_foreign_checkout_never_mutates_even_when_numeric_ids_collide(string $type): void
    {
        $shoot = Shoot::factory()->create(['total_quote' => 1, 'payment_status' => 'unpaid']);
        $attempt = $this->attempt($shoot);
        $session = $this->foreignSession($shoot->id);
        $session['metadata']['checkout_attempt_id'] = (string) $attempt->id;
        $before = $shoot->fresh()->getRawOriginal();

        $this->postEvent($type, $session)->assertOk()
            ->assertJsonPath('handled', false)->assertJsonPath('outcome', 'ignored_foreign_checkout');

        $this->assertSame($before, $shoot->fresh()->getRawOriginal());
        $this->assertSame(StripeCheckoutAttempt::STATUS_OPEN, $attempt->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_refunds', 0);
        Queue::assertNothingPushed();
    }

    public function test_foreign_nonexistent_shoot_is_acknowledged_without_creating_records(): void
    {
        $this->postEvent('checkout.session.completed', $this->foreignSession(465380))
            ->assertOk()->assertJsonPath('outcome', 'ignored_foreign_checkout');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stripe_checkout_attempts', 0);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public static function unprovenOrigins(): array
    {
        return [
            ['https://pro.reprophotos.com.evil.test/paid'],
            ['https://pro.reprophotos.com@evil.test/paid'],
            ['https://evil.test/pro.reprophotos.com'],
            ['http://pro.reprophotos.com/paid'],
            ['https://pro.reprophotos.com:8443/paid'],
            [''],
        ];
    }

    #[DataProvider('unprovenOrigins')]
    public function test_unknown_origin_keeps_retry_semantics_instead_of_ignoring(string $url): void
    {
        $session = $this->foreignSession(465380);
        $session['success_url'] = $url;
        $this->postEvent('checkout.session.completed', $session)->assertStatus(500)
            ->assertJsonPath('status', 'retry');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_foreign_hostname_without_legacy_namespace_is_ambiguous(): void
    {
        $session = $this->foreignSession(465380);
        unset($session['metadata']['company_id']);
        $this->postEvent('checkout.session.completed', $session)->assertStatus(500);
    }

    public function test_foreign_origin_conflicting_with_local_provider_reference_retries_without_mutation(): void
    {
        $shoot = Shoot::factory()->create();
        $attempt = $this->attempt($shoot, 'cs_test_foreign');
        $this->postEvent('checkout.session.completed', $this->foreignSession($shoot->id))
            ->assertStatus(500)->assertJsonPath('handled', false);
        $this->assertSame(StripeCheckoutAttempt::STATUS_OPEN, $attempt->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_owned_marker_conflicting_with_foreign_origin_is_not_fulfilled(): void
    {
        $session = $this->foreignSession(465380);
        $session['metadata']['app_instance'] = 'reprodashboard-testing';
        $this->assertSame(StripeCheckoutOwnership::CONFLICT, app(StripeCheckoutOwnership::class)->classify($session));
        $this->postEvent('checkout.session.completed', $session)->assertStatus(500);
    }

    public function test_owned_missing_shoot_remains_retryable(): void
    {
        $session = $this->localSession(465380);
        $session['metadata']['app_instance'] = 'reprodashboard-testing';
        unset($session['success_url'], $session['cancel_url']);
        $this->postEvent('checkout.session.completed', $session)->assertStatus(500)
            ->assertJsonPath('outcome', 'failed');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_legacy_local_checkout_reconciles_and_duplicate_delivery_is_idempotent(): void
    {
        $shoot = Shoot::factory()->create(['total_quote' => 100, 'payment_status' => 'unpaid']);
        $session = $this->localSession($shoot->id);
        $this->postEvent('checkout.session.completed', $session)->assertOk()->assertJsonPath('outcome', 'processed');
        $this->postEvent('checkout.session.completed', $session)->assertOk()->assertJsonPath('outcome', 'already_processed');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['shoot_id' => $shoot->id, 'amount' => 100, 'stripe_payment_id' => 'pi_test_local']);
        $this->assertSame('paid', $shoot->fresh()->payment_status);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_legacy_embedded_return_url_establishes_local_provenance(): void
    {
        $session = $this->localSession(123);
        unset($session['success_url'], $session['cancel_url']);
        $session['return_url'] = 'https://reprodashboard.com/payments?session_id={CHECKOUT_SESSION_ID}';
        $this->assertSame(StripeCheckoutOwnership::OWNED, app(StripeCheckoutOwnership::class)->classify($session));
        $session['metadata']['environment'] = 'another-environment';
        $this->assertSame(StripeCheckoutOwnership::AMBIGUOUS, app(StripeCheckoutOwnership::class)->classify($session));
    }

    public function test_numeric_local_metadata_without_provenance_is_not_ownership(): void
    {
        $shoot = Shoot::factory()->create();
        $attempt = $this->attempt($shoot);
        $session = $this->localSession($shoot->id);
        unset($session['success_url'], $session['cancel_url']);
        $session['metadata']['client_id'] = (string) $shoot->client_id;
        $session['metadata']['checkout_attempt_id'] = (string) $attempt->id;
        $this->postEvent('checkout.session.completed', $session)->assertStatus(500);
        $this->assertSame(StripeCheckoutAttempt::STATUS_OPEN, $attempt->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_exact_grouped_session_reference_is_owned_but_sql_wildcards_are_not(): void
    {
        $shoot = Shoot::factory()->create();
        Payment::factory()->create([
            'shoot_id' => $shoot->id, 'stripe_session_id' => 'cs_test_group_shoot_'.$shoot->id,
            'stripe_payment_id' => 'pi_test_group', 'payment_method' => 'stripe',
        ]);
        $service = app(StripeCheckoutOwnership::class);
        $this->assertTrue($service->hasLocalReference('cs_test_group', ''));
        $this->assertTrue($service->hasLocalReference('', 'pi_test_group'));
        $this->assertFalse($service->hasLocalReference('cs_test_%', ''));
        $this->assertFalse($service->hasLocalReference('cs_test', ''));
    }

    public static function lifecycleEvents(): array
    {
        return [
            ['checkout.session.expired', StripeCheckoutAttempt::STATUS_EXPIRED],
            ['checkout.session.async_payment_failed', StripeCheckoutAttempt::STATUS_FAILED],
        ];
    }

    #[DataProvider('lifecycleEvents')]
    public function test_lifecycle_updates_require_the_exact_stored_session(string $type, string $status): void
    {
        $shoot = Shoot::factory()->create();
        $attempt = $this->attempt($shoot);
        $session = $this->localSession($shoot->id);
        $session['metadata']['app_instance'] = 'reprodashboard-testing';
        $session['metadata']['checkout_attempt_id'] = (string) $attempt->id;
        $missingSessionId = $session;
        $missingSessionId['id'] = '';
        $this->postEvent($type, $missingSessionId)->assertStatus(500);
        $this->assertSame(StripeCheckoutAttempt::STATUS_OPEN, $attempt->fresh()->status);
        $this->postEvent($type, $session)->assertStatus(500);
        $this->assertSame(StripeCheckoutAttempt::STATUS_OPEN, $attempt->fresh()->status);

        $session['id'] = $attempt->stripe_session_id;
        $this->postEvent($type, $session)->assertOk();
        $this->assertSame($status, $attempt->fresh()->status);

        $attempt->update(['status' => StripeCheckoutAttempt::STATUS_PAID]);
        $this->postEvent($type, $session)->assertOk();
        $this->assertSame(StripeCheckoutAttempt::STATUS_PAID, $attempt->fresh()->status);
    }

    public function test_unmapped_refund_is_retryable_despite_colliding_local_payment_metadata(): void
    {
        $payment = Payment::factory()->create(['stripe_payment_id' => 'pi_local', 'payment_method' => 'stripe']);
        $before = $payment->fresh()->getRawOriginal();
        $this->postEvent('refund.created', [
            'id' => 're_foreign', 'object' => 'refund', 'payment_intent' => 'pi_foreign',
            'amount' => 1000, 'status' => 'succeeded', 'currency' => 'usd',
            'metadata' => ['app_payment_id' => (string) $payment->id],
        ])->assertStatus(500)->assertJsonPath('handled', false);
        $this->assertSame($before, $payment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_invalid_signature_is_still_rejected(): void
    {
        $this->postEvent('checkout.session.completed', $this->foreignSession(465380), 'wrong-secret')->assertStatus(400);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_browser_reconciliation_rejects_foreign_session_before_payment_status_sync(): void
    {
        $shoot = Shoot::factory()->create(['total_quote' => 0, 'payment_status' => 'unpaid']);
        try {
            app(StripePaymentController::class)->reconcileShootPayments(
                $shoot, null, json_decode(json_encode($this->foreignSession($shoot->id)))
            );
            $this->fail('Foreign checkout must not be reconciled.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Stripe Checkout ownership could not be established.', $exception->getMessage());
        }
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_internal_test_shoot_cannot_create_checkout_or_receive_a_payment(): void
    {
        $client = User::factory()->create();
        $shoot = Shoot::factory()->for($client, 'client')->create([
            'shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST, 'total_quote' => 100, 'payment_status' => 'unpaid',
        ]);
        Sanctum::actingAs($client);
        $this->postJson("/api/shoots/{$shoot->id}/create-stripe-embedded-checkout", ['amount' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->postEvent('checkout.session.completed', $this->localSession($shoot->id))->assertStatus(500);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stripe_checkout_attempts', 0);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_production_diagnostic_retains_only_fixed_reason_and_hashed_provider_identifiers(): void
    {
        $safe = (new PrivacyLogProcessor)(new LogRecord(
            new \DateTimeImmutable, 'stripe-webhooks', Level::Notice, 'Stripe webhook ownership decision.', [
                'reason' => 'foreign', 'event_type' => 'checkout.session.completed',
                'event_hash' => hash('sha256', 'evt_private'), 'object_hash' => hash('sha256', 'cs_private'),
                'intent_hash' => 'pi_should_not_survive', 'payload' => ['email' => 'private@example.test'],
                'success_url' => 'https://example.test/private', 'session_id' => 'cs_private',
            ], ['unsafe' => 'extra']
        ));
        $this->assertSame('Stripe webhook ownership decision.', $safe->message);
        $this->assertSame('foreign', $safe->context['reason']);
        $this->assertSame(hash('sha256', 'evt_private'), $safe->context['event_hash']);
        $serialized = json_encode([$safe->context, $safe->extra]);
        $this->assertStringNotContainsString('private', $serialized);
        $this->assertStringNotContainsString('should_not_survive', $serialized);
        $this->assertSame([], $safe->extra);
        $this->assertSame('notice', config('logging.channels.stripe-webhooks.level'));
        $this->assertSame(0660, config('logging.channels.stripe-webhooks.permission'));
    }

    private function attempt(Shoot $shoot, string $sessionId = 'cs_local_attempt'): StripeCheckoutAttempt
    {
        return StripeCheckoutAttempt::create([
            'client_id' => $shoot->client_id, 'scope' => 'single_embedded', 'ui_mode' => 'embedded',
            'expected_amount_cents' => 10000, 'currency' => 'USD', 'status' => StripeCheckoutAttempt::STATUS_OPEN,
            'request_fingerprint' => hash('sha256', $sessionId), 'idempotency_key' => 'test_'.$sessionId,
            'stripe_session_id' => $sessionId,
        ]);
    }

    private function foreignSession(int $shootId): array
    {
        return [
            'id' => 'cs_test_foreign', 'object' => 'checkout.session', 'mode' => 'payment',
            'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_foreign',
            'amount_total' => 10000, 'currency' => 'usd',
            'success_url' => 'https://pro.reprophotos.com/payment/success',
            'cancel_url' => 'https://pro.reprophotos.com/payment/cancel',
            'metadata' => ['company_id' => '123', 'shoot_id' => (string) $shootId],
        ];
    }

    private function localSession(int $shootId): array
    {
        return array_replace($this->foreignSession($shootId), [
            'id' => 'cs_test_local', 'payment_intent' => 'pi_test_local',
            'success_url' => 'https://reprodashboard.com/payment/success',
            'cancel_url' => 'https://reprodashboard.com/payment/cancel',
            'metadata' => ['type' => 'single', 'shoot_id' => (string) $shootId, 'environment' => 'testing'],
        ]);
    }

    private function postEvent(string $type, array $object, string $secret = 'whsec_local_ownership_test')
    {
        $payload = json_encode([
            'id' => 'evt_local_ownership', 'object' => 'event', 'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, $secret);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature,
        ], $payload);
    }
}
