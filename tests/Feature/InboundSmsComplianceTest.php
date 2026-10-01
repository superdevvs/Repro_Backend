<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\AiSms\SmsAiAgentService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use App\Services\ReproAi\ReproAiOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InboundSmsComplianceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('complianceKeywords')]
    public function test_compliance_works_with_ai_disabled_and_replies_from_the_inbound_number(string $keyword, bool $globalEnabled): void
    {
        config()->set('services.telnyx.ai_sms_enabled', $globalEnabled);
        config()->set('services.telnyx.public_key', '');
        $number = $this->createNumbers(false);
        $user = User::factory()->create([
            'phonenumber' => '+12025550188',
            'sms_opt_out' => $keyword !== 'STOP',
            'sms_ai_enabled' => false,
            'metadata' => ['preferences' => ['notificationSMS' => false]],
        ]);
        $contact = Contact::create([
            'name' => 'SMS Client', 'phone' => $user->phonenumber, 'type' => 'client',
            'sms_opt_out' => $keyword !== 'STOP', 'sms_ai_enabled' => false,
        ]);
        $this->mock(ReproAiOrchestrator::class)->shouldNotReceive('handle');
        $this->expectReplyFrom($number);

        $payload = $this->inboundPayload($keyword, $number);
        $this->postJson('/api/webhooks/telnyx/messaging', $payload)->assertOk()->assertJsonPath('status', 'received');
        $this->postJson('/api/webhooks/telnyx/messaging', $payload)->assertOk()->assertJsonPath('status', 'duplicate');
        $inbound = Message::where('direction', 'INBOUND')->sole();
        app(SmsAiAgentService::class)->handleInbound($inbound);

        $outbound = Message::where('direction', 'OUTBOUND')->sole();
        $this->assertSame($number->phone_number, $outbound->from_address);
        $this->assertSame('SENT', $outbound->status);
        $this->assertSame('SMS_COMPLIANCE', $outbound->send_source);
        $this->assertFalse($outbound->metadata['ai_generated']);
        $this->assertSame(strtolower($keyword), $inbound->fresh()->metadata['compliance_keyword']);
        $this->assertSame($keyword !== 'START', (bool) $user->fresh()->sms_opt_out);
        $this->assertSame($keyword !== 'START', (bool) $contact->fresh()->sms_opt_out);
        $this->assertFalse($user->fresh()->sms_ai_enabled);
        $this->assertFalse($contact->fresh()->sms_ai_enabled);
        $this->assertFalse($number->fresh()->sms_ai_enabled);
        $this->assertSame($globalEnabled, config('services.telnyx.ai_sms_enabled'));
    }

    public static function complianceKeywords(): array
    {
        return [
            'stop global disabled' => ['STOP', false],
            'start global disabled' => ['START', false],
            'help global disabled' => ['HELP', false],
            'stop number disabled' => ['STOP', true],
            'start number disabled' => ['START', true],
            'help number disabled' => ['HELP', true],
        ];
    }

    public function test_ordinary_inbound_does_not_start_ai_when_globally_disabled(): void
    {
        config()->set('services.telnyx.ai_sms_enabled', false);
        config()->set('services.telnyx.public_key', '');
        $number = $this->createNumbers(false);
        $this->mock(ReproAiOrchestrator::class)->shouldNotReceive('handle');
        $this->mock(TelnyxSmsProvider::class)->shouldNotReceive('send');

        $this->postJson('/api/webhooks/telnyx/messaging', $this->inboundPayload('When is my shoot?', $number))->assertOk();
        app(SmsAiAgentService::class)->handleInbound(Message::where('direction', 'INBOUND')->sole());

        $this->assertSame(0, Message::where('direction', 'OUTBOUND')->count());
        $this->assertSame('global_disabled', Message::where('direction', 'INBOUND')->sole()->metadata['skip_reason']);
    }

    public function test_ai_reply_uses_the_inbound_number_instead_of_the_default_sender(): void
    {
        config()->set('services.telnyx.ai_sms_enabled', true);
        config()->set('services.telnyx.public_key', '');
        $number = $this->createNumbers(true);
        $user = User::factory()->create(['phonenumber' => '+12025550188', 'sms_ai_enabled' => true]);
        Contact::create(['name' => 'SMS Client', 'phone' => $user->phonenumber, 'type' => 'client', 'sms_ai_enabled' => true]);
        $this->mock(ReproAiOrchestrator::class)->shouldReceive('handle')->once()->andReturn([
            ['content' => 'Your shoot is tomorrow.', 'metadata' => []],
        ]);
        $this->expectReplyFrom($number);

        $this->postJson('/api/webhooks/telnyx/messaging', $this->inboundPayload('When is my shoot?', $number))->assertOk();

        $outbound = Message::where('direction', 'OUTBOUND')->sole();
        $this->assertSame($number->phone_number, $outbound->from_address);
        $this->assertSame('Your shoot is tomorrow.', $outbound->body_text);
        $this->assertSame('AI_SMS_AGENT', $outbound->send_source);
        $this->assertTrue($outbound->metadata['ai_generated']);
    }

    private function createNumbers(bool $aiEnabled): SmsNumber
    {
        SmsNumber::create(['phone_number' => '+12025550101', 'provider' => 'TELNYX', 'is_default' => true, 'sms_ai_enabled' => false]);

        return SmsNumber::create(['phone_number' => '+12025550102', 'provider' => 'TELNYX', 'is_default' => false, 'sms_ai_enabled' => $aiEnabled]);
    }

    private function expectReplyFrom(SmsNumber $number): void
    {
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $this->mock(TelnyxSmsProvider::class)->shouldReceive('send')->once()->with(
            Mockery::on(fn (SmsNumber $sender): bool => $sender->is($number)),
            Mockery::on(fn (array $payload): bool => $payload['to'] === '+12025550188' && $payload['text'] !== '')
        )->andReturn('fake-compliance-reply');
    }

    private function inboundPayload(string $body, SmsNumber $number): array
    {
        return ['data' => [
            'id' => 'fake-inbound-event', 'event_type' => 'message.received',
            'payload' => [
                'id' => 'fake-inbound-message', 'text' => $body,
                'from' => ['phone_number' => '+12025550188'],
                'to' => [['phone_number' => $number->phone_number]],
            ],
        ]];
    }
}
