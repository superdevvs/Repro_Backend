<?php

namespace Tests\Feature;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Shoot;
use App\Services\Messaging\MessagingService;
use App\Services\SystemEmails\DirectEmailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebsiteEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.external_booking.api_key' => 'website-email-test-key']);
    }

    private function inquiry(): array
    {
        return ['type' => 'contact', 'reference' => 'af3446f3-589e-4400-bc26-afc9f2a39468', 'contact' => [
            'first_name' => 'Casey', 'last_name' => 'Visitor', 'email' => 'visitor@example.test', 'phone' => '2025550184',
            'message' => "Please photograph the blue entryway.\n<script>alert('x')</script>",
            'sms_consent' => false, 'created_at' => '2026-10-10T10:00:00Z',
        ]];
    }

    private function send(array $body)
    {
        return $this->withHeader('X-API-Key', 'website-email-test-key')->postJson('/api/external/website-email', $body);
    }

    public function test_auth_and_validation_prevent_arbitrary_email_relay(): void
    {
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldNotReceive('sendEmail');
        $this->app->instance(MessagingService::class, $mock);
        $this->postJson('/api/external/website-email', $this->inquiry())->assertUnauthorized();
        $this->withHeader('X-API-Key', 'wrong')->postJson('/api/external/website-email', $this->inquiry())->assertForbidden();
        $body = $this->inquiry();
        $body['contact']['email'] = "victim@example.test\r\nBcc: other@example.test";
        $this->send($body)->assertUnprocessable();
        $this->send(['type' => 'other', 'reference' => $body['reference']])->assertUnprocessable();
    }

    public function test_contact_uses_real_editable_dashboard_template_sender_and_reply_to_once(): void
    {
        DirectEmailTemplates::installMissing();
        MessageTemplate::where('slug', 'contact-notification')->update([
            'body_html' => '<p>Saved office introduction</p>{{contact_inquiry_html}}',
            'body_text' => 'Saved office introduction {{contact_inquiry_text}}',
        ]);
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendEmail')->once()->withArgs(function ($payload) {
            $this->assertSame('contact@reprophotos.com', $payload['from']);
            $this->assertSame('contact@reprophotos.com', $payload['to']);
            $this->assertSame('R/E Pro Photos', $payload['sender_name']);
            $this->assertSame('visitor@example.test', $payload['reply_to']);
            $this->assertSame('WEBSITE_CONTACT', $payload['send_source']);
            foreach (['Saved office introduction', 'atelier-v6', 'Casey Visitor', 'blue entryway', 'SMS consent', 'af3446f3-589e-4400-bc26-afc9f2a39468', '&lt;script&gt;'] as $needle) {
                $this->assertStringContainsString($needle, $payload['body_html']);
            }
            $this->assertStringNotContainsString('<script>', $payload['body_html']);
            $this->assertStringContainsString('blue entryway', $payload['body_text']);

            return true;
        })->andReturn(new Message(['id' => 42, 'status' => 'SENT']));
        $this->app->instance(MessagingService::class, $mock);
        $this->send($this->inquiry())->assertOk()->assertJsonPath('outcome', 'delivered');
        $this->send($this->inquiry())->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('ok', true);
        $changed = $this->inquiry();
        $changed['contact']['message'] = 'Different message';
        $this->send($changed)->assertConflict();
        $this->assertDatabaseHas('website_email_deliveries', ['status' => 'delivered']);
    }

    public function test_disabled_template_does_not_report_delivery(): void
    {
        DirectEmailTemplates::installMissing();
        MessageTemplate::where('slug', 'contact-notification')->update(['is_active' => false]);
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldNotReceive('sendEmail');
        $this->app->instance(MessagingService::class, $mock);
        $this->send($this->inquiry())->assertOk()->assertJsonPath('ok', false)->assertJsonPath('outcome', 'failed');
    }

    public function test_ambiguous_provider_error_cannot_resend_on_retry(): void
    {
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendEmail')->once()->andThrow(new \RuntimeException('network timeout'));
        $this->app->instance(MessagingService::class, $mock);
        $this->send($this->inquiry())->assertOk()->assertJsonPath('outcome', 'uncertain');
        $this->send($this->inquiry())->assertOk()->assertJsonPath('outcome', 'uncertain')->assertJsonPath('duplicate', true);
    }

    public function test_definitive_rejection_can_be_retried(): void
    {
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendEmail')->once()->ordered()->andThrow(new EmailProviderRejectedException('rejected'));
        $mock->shouldReceive('sendEmail')->once()->ordered()->andReturn(new Message(['status' => 'SENT']));
        $this->app->instance(MessagingService::class, $mock);
        $this->send($this->inquiry())->assertOk()->assertJsonPath('outcome', 'failed');
        $this->send($this->inquiry())->assertOk()->assertJsonPath('outcome', 'delivered');
    }

    public function test_existing_in_flight_claim_is_never_resent(): void
    {
        $contact = $this->inquiry()['contact'];
        ksort($contact);
        \Illuminate\Support\Facades\DB::table('website_email_deliveries')->insert([
            'type' => 'contact', 'reference' => $this->inquiry()['reference'],
            'payload_hash' => hash('sha256', json_encode($contact, JSON_THROW_ON_ERROR)),
            'status' => 'sending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldNotReceive('sendEmail');
        $this->app->instance(MessagingService::class, $mock);
        $this->send($this->inquiry())->assertOk()->assertJsonPath('outcome', 'uncertain');
    }

    public function test_blocked_message_is_not_delivery_success(): void
    {
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendEmail')->once()->andReturn(new Message(['status' => 'BLOCKED']));
        $this->app->instance(MessagingService::class, $mock);
        $this->send($this->inquiry())->assertOk()->assertJsonPath('outcome', 'failed')->assertJsonPath('ok', false);
    }

    public function test_booking_is_bound_to_saved_website_shoot_and_uses_dashboard_office_template(): void
    {
        $body = ['type' => 'booking', 'reference' => $this->inquiry()['reference']];
        $shoot = Shoot::factory()->create(['address' => '901 Website Ave', 'city' => 'Baltimore', 'state' => 'MD',
            'external_booking_payload' => ['source' => 'reprophotos.com', 'external_reference' => $body['reference']],
            'scheduled_date' => '2026-10-15', 'total_quote' => 185]);
        $service = Service::factory()->create(['name' => 'Distinctive Exterior Photography', 'price' => 185]);
        $shoot->services()->attach($service->id, ['price' => 185, 'quantity' => 1]);
        $body['shoot_id'] = $shoot->id;
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendEmail')->once()->withArgs(function ($payload) use ($shoot) {
            $this->assertSame('contact@reprophotos.com', $payload['from']);
            $this->assertSame($shoot->id, $payload['related_shoot_id']);
            foreach (['atelier-v6', '901 Website Ave', 'Distinctive Exterior Photography', 'Open Dashboard', 'review'] as $needle) {
                $this->assertStringContainsString($needle, $payload['body_html']);
            }
            $this->assertStringNotContainsString('INJECTED SERVICE', $payload['body_html']);

            return true;
        })->andReturn(new Message(['status' => 'SENT']));
        $this->app->instance(MessagingService::class, $mock);
        $bad = $body;
        $bad['reference'] = 'bf3446f3-589e-4400-bc26-afc9f2a39468';
        $this->send($bad)->assertUnprocessable();
        $body['lines'] = [['name' => 'INJECTED SERVICE']];
        $this->send($body)->assertOk()->assertJsonPath('outcome', 'delivered');
    }
}
