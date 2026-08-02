<?php

namespace Tests\Feature;

use App\Models\SmsNumber;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\Providers\CakemailProvider;
use App\Services\Messaging\Providers\FakeCakemailProvider;
use App\Services\Messaging\Providers\FakeEmailProvider;
use App\Services\Messaging\Providers\FakeLocalSmtpProvider;
use App\Services\Messaging\Providers\FakeSmsProvider;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proves a test run cannot reach a live messaging provider.
 *
 * During Phase C a reminder sent from local hit the real Telnyx API, because the
 * local environment file carries real credentials and nothing above the provider
 * asked whether delivery was appropriate. Two independent defences now exist and
 * both are asserted here:
 *
 *   1. the concrete providers are swapped for in-memory fakes in `testing`;
 *   2. outbound HTTP is faked with stray requests prevented, so even a provider
 *      built outside the container cannot open a connection.
 */
class MessagingProviderIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSmsProvider::reset();
        FakeEmailProvider::reset();
    }

    public function test_the_container_resolves_fake_providers_in_testing(): void
    {
        $this->assertInstanceOf(FakeSmsProvider::class, app(TelnyxSmsProvider::class));
        $this->assertInstanceOf(FakeCakemailProvider::class, app(CakemailProvider::class));
        $this->assertInstanceOf(FakeLocalSmtpProvider::class, app(LocalSmtpProvider::class));
    }

    public function test_messaging_service_receives_the_fake_sms_provider(): void
    {
        // Guards against a future constructor change reintroducing the real
        // provider into the service that actually sends.
        $service = app(MessagingService::class);

        $reflected = new \ReflectionProperty($service, 'telnyxProvider');
        $reflected->setAccessible(true);

        $this->assertInstanceOf(FakeSmsProvider::class, $reflected->getValue($service));
    }

    public function test_the_fake_sms_provider_records_instead_of_sending(): void
    {
        $number = new SmsNumber(['phone_number' => '+15550000000']);
        $number->id = 1;

        $provider = app(TelnyxSmsProvider::class);
        $id = $provider->send($number, ['to' => '+15552223333', 'text' => 'hello']);

        $this->assertStringStartsWith('fake-sms-', $id);
        $this->assertSame('+15552223333', FakeSmsProvider::sent()[0]['to']);
    }

    public function test_no_request_reaches_the_telnyx_domain(): void
    {
        $number = new SmsNumber(['phone_number' => '+15550000000']);
        $number->id = 1;

        app(TelnyxSmsProvider::class)->send($number, ['to' => '+15552223333', 'text' => 'hello']);

        Http::assertNothingSent();
    }

    public function test_the_delivery_guard_blocks_sms_in_the_testing_environment(): void
    {
        $guard = app(\App\Services\Messaging\OutboundDeliveryGuard::class);

        $this->assertFalse($guard->allows('SMS', '+14155230000'));
        $this->assertFalse($guard->allows('EMAIL', 'someone@realdomain.co'));
    }

    public function test_a_blocked_message_is_recorded_locally_rather_than_sent(): void
    {
        SmsNumber::query()->forceCreate([
            'phone_number' => '+15550000000',
            'label' => 'A1 isolation test',
            'is_default' => true,
        ]);

        $message = app(MessagingService::class)->sendSms([
            'to' => '+14155230000',
            'body_text' => 'Balance reminder',
            'send_source' => 'AUTOMATION',
        ]);

        // The intent is preserved for inspection, the delivery is not attempted.
        $this->assertSame('BLOCKED', $message->status);
        $this->assertNull($message->sent_at);
        $this->assertStringContainsString('blocked', strtolower((string) $message->error_message));
        $this->assertSame([], FakeSmsProvider::sent(), 'The provider should not be reached at all.');
        Http::assertNothingSent();
    }

    public function test_the_blocked_record_stores_no_credential_material(): void
    {
        SmsNumber::query()->forceCreate([
            'phone_number' => '+15550000001',
            'label' => 'A1 isolation test 2',
            'is_default' => true,
        ]);

        $message = app(MessagingService::class)->sendSms([
            'to' => '+14155230001',
            'body_text' => 'Balance reminder',
            'send_source' => 'AUTOMATION',
        ]);

        $serialized = strtolower(json_encode($message->toArray()) ?: '');

        foreach (['api_key', 'apikey', 'secret', 'bearer', 'token', 'password'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $serialized,
                "A blocked message record must not carry {$needle} material."
            );
        }
    }
}
