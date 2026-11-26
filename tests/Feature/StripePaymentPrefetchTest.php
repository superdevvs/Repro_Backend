<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Shoot;
use App\Services\Payments\StripePaymentMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * The checkout webhook asks Stripe for the receipt details before it opens the
 * payment transaction, then records them inside it without another call.
 */
class StripePaymentPrefetchTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $requests = [];

    private bool $stripeFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret_key' => 'sk_test_prefetch_fixture']);
        $test = $this;
        ApiRequestor::setHttpClient(new class($test) implements ClientInterface
        {
            public function __construct(private StripePaymentPrefetchTest $test) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return $this->test->answer($method, (string) parse_url($absUrl, PHP_URL_PATH));
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    public function answer(string $method, string $path): array
    {
        $this->requests[] = $method.' '.$path;
        if ($this->stripeFails) {
            return [json_encode(['error' => ['type' => 'api_error', 'message' => 'Stripe is unavailable.']]), 500, []];
        }

        return [json_encode(['id' => 'pi_prefetch', 'object' => 'payment_intent', 'status' => 'succeeded',
            'payment_method' => 'pm_prefetch', 'latest_charge' => ['id' => 'ch_prefetch', 'object' => 'charge',
                'receipt_url' => 'https://pay.stripe.com/receipts/prefetch', 'receipt_number' => '1000-0002',
                'amount' => 12500, 'created' => 1795000000, 'billing_details' => ['email' => 'client@example.test'],
                'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]]]), 200, []];
    }

    public function test_prefetched_details_are_recorded_without_asking_stripe_again(): void
    {
        $service = app(StripePaymentMetadataService::class);
        $objects = $service->prefetchStripeObjects('pi_prefetch');
        $this->assertSame(['get /v1/payment_intents/pi_prefetch'], $this->requests);

        $payment = $service->hydratePaymentRecord($this->payment(), $this->checkoutSession(), $objects);

        $this->assertCount(1, $this->requests, 'Recording the payment must not call Stripe again.');
        $details = $payment->payment_details;
        $this->assertSame('cs_prefetch', $details['checkout_session_id']);
        $this->assertSame('pi_prefetch', $details['payment_intent_id']);
        $this->assertSame('succeeded', $details['payment_intent_status']);
        $this->assertSame('ch_prefetch', $details['charge_id']);
        $this->assertSame('https://pay.stripe.com/receipts/prefetch', $details['hosted_receipt_url']);
        $this->assertSame('4242', $details['payment_method_last4']);
        $this->assertEquals(125, $details['charged_amount']);
    }

    public function test_without_prefetched_details_the_payment_still_asks_stripe_once(): void
    {
        $payment = app(StripePaymentMetadataService::class)->hydratePaymentRecord($this->payment(), $this->checkoutSession());

        $this->assertSame(['get /v1/payment_intents/pi_prefetch'], $this->requests);
        $this->assertSame('ch_prefetch', $payment->payment_details['charge_id']);
    }

    public function test_a_failed_prefetch_is_logged_once_recorded_and_keeps_the_checkout_details(): void
    {
        $this->stripeFails = true;
        Log::spy();
        $service = app(StripePaymentMetadataService::class);
        $objects = $service->prefetchStripeObjects('pi_prefetch');
        $requests = count($this->requests);

        $payment = $service->hydratePaymentRecord($this->payment(), $this->checkoutSession(), $objects);

        $this->assertSame($requests, count($this->requests), 'A failed lookup is not retried inside the transaction.');
        $this->assertSame('cs_prefetch', $payment->payment_details['checkout_session_id']);
        $this->assertArrayNotHasKey('charge_id', $payment->payment_details);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Unable to enrich Stripe payment metadata.'
            && $context['payment_id'] === $payment->id && $context['stripe_payment_id'] === 'pi_prefetch');
    }

    private function payment(): Payment
    {
        return Payment::create(['shoot_id' => Shoot::factory()->create()->id, 'amount' => 125, 'currency' => 'USD',
            'payment_method' => 'stripe', 'stripe_payment_id' => 'pi_prefetch', 'stripe_session_id' => 'cs_prefetch',
            'status' => Payment::STATUS_COMPLETED, 'processed_at' => now()]);
    }

    private function checkoutSession(): Session
    {
        return Session::constructFrom(['id' => 'cs_prefetch', 'object' => 'checkout.session', 'payment_status' => 'paid',
            'amount_total' => 12500, 'currency' => 'usd', 'customer_details' => ['email' => 'client@example.test', 'name' => 'Client']]);
    }
}
