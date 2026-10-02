<?php

namespace Tests\Feature;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\Message;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\FakeCakemailProvider;
use App\Services\Messaging\Providers\ResendProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResendMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.email_primary' => 'RESEND', 'services.resend.key' => 're_test', 'services.resend.domain_verified' => true]);
        Http::preventStrayRequests();
        $this->app->instance(ResendProvider::class, new ResendProvider);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
    }

    private function payload(): array
    {
        return ['to' => 'client@example.com', 'subject' => 'Provider check', 'body_html' => '<p>Hello</p>', 'body_text' => 'Hello', 'reply_to' => 'contact@reprophotos.com', 'send_source' => 'PASSWORD_RESET'];
    }

    public function test_resend_preserves_content_recipients_attachments_and_records_actual_provider(): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'resend-123'])]);
        $message = app(MessagingService::class)->sendEmail($this->payload() + [
            'cc' => ['copy@example.com'], 'bcc' => ['blind@example.com'],
            'attachments' => [['filename' => 'invoice.pdf', 'content' => "%PDF\0binary"]],
        ]);
        $this->assertSame('RESEND', $message->provider);
        $this->assertSame('resend-123', $message->provider_message_id);
        $this->assertSame('SENT', $message->status);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer re_test')
            && $request->hasHeader('Idempotency-Key', 'repro-message-'.$message->id)
            && $request['from'] === 'R/E Pro Photos <noreply@reprophotos.com>'
            && $request['to'] === ['client@example.com'] && $request['cc'] === ['copy@example.com']
            && $request['bcc'] === ['blind@example.com'] && $request['html'] === '<p>Hello</p>'
            && $request['reply_to'] === 'contact@reprophotos.com'
            && $request['attachments'][0]['content'] === base64_encode("%PDF\0binary"));
        $this->assertCount(0, FakeCakemailProvider::sent());
    }

    public static function rejectedResponses(): array
    {
        return array_map(fn ($status) => [$status], [400, 401, 403, 404, 422, 429]);
    }

    #[DataProvider('rejectedResponses')]
    public function test_confirmed_rejection_uses_cakemail_and_preserves_audit(int $status): void
    {
        Http::fake(['api.resend.com/emails' => Http::response(['name' => 'rejected'], $status)]);
        $message = app(MessagingService::class)->sendEmail($this->payload());
        $this->assertSame('SENT', $message->status);
        $this->assertSame('CAKEMAIL', $message->provider);
        $this->assertSame('CAKEMAIL', data_get($message->metadata, 'delivery.provider'));
        $this->assertStringContainsString('Resend send failed', data_get($message->metadata, 'delivery.fallback_reason'));
        $this->assertCount(1, FakeCakemailProvider::sent());
        Http::assertSentCount(1);
    }

    public static function ambiguousResponses(): array
    {
        return [[409, ['name' => 'concurrent_idempotent_requests']], [500, ['name' => 'application_error']], [503, ['name' => 'service_unavailable']], [200, []]];
    }

    #[DataProvider('ambiguousResponses')]
    public function test_uncertain_acceptance_never_falls_back(int $status, array $body): void
    {
        Http::fake(['api.resend.com/emails' => Http::response($body, $status)]);
        try {
            app(MessagingService::class)->sendEmail($this->payload());
            $this->fail('An uncertain result must surface.');
        } catch (\RuntimeException $exception) {
            $this->assertNotInstanceOf(EmailProviderRejectedException::class, $exception);
        }
        $this->assertCount(0, FakeCakemailProvider::sent());
        $this->assertFalse(data_get(Message::latest('id')->first()->metadata, 'delivery.provider_rejected'));
    }

    public function test_timeout_never_falls_back(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        try {
            app(MessagingService::class)->sendEmail($this->payload());
            $this->fail('A timeout must surface.');
        } catch (ConnectionException $exception) {
            $this->assertSame('timeout', $exception->getMessage());
        }
        $this->assertCount(0, FakeCakemailProvider::sent());
    }

    public function test_missing_key_falls_back_before_any_resend_http_request(): void
    {
        config(['services.resend.key' => null]);
        $message = app(MessagingService::class)->sendEmail($this->payload());
        $this->assertSame('CAKEMAIL', $message->provider);
        Http::assertNothingSent();
    }

    public function test_scheduled_email_uses_primary_at_dispatch_with_stable_key(): void
    {
        config(['messaging.email_primary' => 'CAKEMAIL']);
        $service = app(MessagingService::class);
        $message = $service->scheduleEmail($this->payload(), now()->addHour());
        Http::assertNothingSent();
        config(['messaging.email_primary' => 'RESEND']);
        Http::fake(['api.resend.com/emails' => Http::response(['id' => 'scheduled-resend'])]);
        $message = $service->dispatchStoredEmailMessage($message);
        $this->assertSame('RESEND', $message->provider);
        $this->assertSame('scheduled-resend', $message->provider_message_id);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'repro-message-'.$message->id)
            && $request['html'] === '<p>Hello</p>');
    }

    public function test_environment_guard_blocks_both_providers(): void
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting(false);
        $message = app(MessagingService::class)->sendEmail($this->payload());
        $this->assertSame('BLOCKED', $message->status);
        Http::assertNothingSent();
        $this->assertCount(0, FakeCakemailProvider::sent());
    }

    public function test_test_environment_container_binds_resend_to_a_fake(): void
    {
        $this->app->forgetInstance(ResendProvider::class);
        $message = app(MessagingService::class)->sendEmail($this->payload());
        $this->assertStringStartsWith('fake-resend-', $message->provider_message_id);
        Http::assertNothingSent();
    }

    public function test_sending_only_key_health_is_supported_without_granting_full_access(): void
    {
        Http::fake(['api.resend.com/domains' => Http::response(['name' => 'restricted_api_key', 'message' => 'This API key is restricted to only send emails.'], 401)]);
        $this->assertTrue((new ResendProvider)->testConnection()['success']);
    }
}
