<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\ReproAi\Flows\ClientCrmFlow;
use App\Services\ReproAi\Flows\InvoiceBillingFlow;
use App\Services\SystemEmails\EmailAtelierTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailAtelierInlineFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        config(['app.url' => 'https://atelier.example.test']);
    }

    public static function designs(): array
    {
        return [
            'thank you' => ['crm_thank_you'],
            'feedback' => ['crm_feedback'],
            'referral' => ['crm_referral'],
            'rebook' => ['crm_rebook'],
            'client invoice' => ['client_invoice'],
        ];
    }

    #[DataProvider('designs')]
    public function test_real_flow_sends_the_migrated_event_specific_v6_email(string $design): void
    {
        $template = $this->template($design);
        $this->assertTrue($template->is_active);
        $this->assertNull($template->email_type);
        [$session, $client, $invoice] = $this->sessionFor($design);
        $sent = $this->captureNextEmail();

        $result = $this->runFlow($design, $session);

        $this->assertTrue(data_get($result, 'assistant_messages.0.metadata.email_sent'));
        $this->assertSame($client->email, $sent->payload['to']);
        $this->assertV6($sent->payload, $design);
        // The shared renderer intentionally removes leading greeting artifacts.
        $this->assertNotEmpty(trim($sent->payload['body_text']));
        $this->assertStringNotContainsString('{{recipient_name}}', $sent->payload['body_text']);
        if ($invoice) {
            $this->assertSame($invoice->id, $sent->payload['related_invoice_id']);
            $this->assertStringContainsString($invoice->paymentLink(), $sent->payload['body_html']);
            $this->assertStringContainsString('$369.00', $sent->payload['body_html']);
            $this->assertStringContainsString('View invoice', $sent->payload['body_html']);
        }
        $this->assertNoOutboundSideEffects();
    }

    #[DataProvider('designs')]
    public function test_real_flow_honors_saved_subject_html_and_text_edits(string $design): void
    {
        $this->template($design)->update([
            'subject' => 'Reviewed update for {{recipient_name}}',
            'body_html' => '<p>Saved editor HTML for {{recipient_name}}.</p>',
            'body_text' => 'Saved editor text for {{recipient_name}}.',
        ]);
        // Installing defaults again must preserve the administrator's saved copy.
        EmailAtelierTemplates::installMissing();
        [$session, $client] = $this->sessionFor($design);
        $sent = $this->captureNextEmail();

        $result = $this->runFlow($design, $session);

        $this->assertTrue(data_get($result, 'assistant_messages.0.metadata.email_sent'));
        $this->assertSame('Reviewed update for '.$client->name, $sent->payload['subject']);
        $this->assertStringContainsString('Saved editor HTML for '.$client->name.'.', $sent->payload['body_html']);
        $this->assertSame('Saved editor text for '.$client->name.'.', $sent->payload['body_text']);
        $this->assertV6($sent->payload, $design);
        $this->assertSame('<p>Saved editor HTML for {{recipient_name}}.</p>', $this->template($design)->body_html);
        $this->assertNoOutboundSideEffects();
    }

    #[DataProvider('designs')]
    public function test_disabled_template_prevents_the_real_flow_from_sending(string $design): void
    {
        $this->template($design)->update(['is_active' => false]);
        EmailAtelierTemplates::installMissing();
        [$session] = $this->sessionFor($design);
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldNotReceive('sendEmail');
        $this->app->instance(MessagingService::class, $messaging);

        $result = $this->runFlow($design, $session);

        $this->assertFalse(data_get($result, 'assistant_messages.0.metadata.email_sent'));
        $this->assertFalse($this->template($design)->is_active);
        $this->assertNoOutboundSideEffects();
    }

    public static function invoiceBalances(): array
    {
        return [
            'partial payment' => [120, '$249.00'],
            'fully paid' => [369, '$0.00'],
            'overpaid' => [400, '$0.00'],
        ];
    }

    #[DataProvider('invoiceBalances')]
    public function test_invoice_flow_reports_remaining_balance_with_no_due_date(float $paid, string $expected): void
    {
        [$session, , $invoice] = $this->sessionFor('client_invoice');
        $invoice->update(['amount_paid' => $paid, 'due_date' => null]);
        $sent = $this->captureNextEmail();

        $result = $this->runFlow('client_invoice', $session);

        $this->assertTrue(data_get($result, 'assistant_messages.0.metadata.email_sent'));
        $this->assertStringContainsString('Amount due: '.$expected, $sent->payload['body_text']);
        $this->assertStringContainsString('Due date: See invoice', $sent->payload['body_text']);
        $this->assertStringContainsString('View invoice', $sent->payload['body_html']);
        $this->assertStringNotContainsString('Pay Now', $sent->payload['body_html']);
        $this->assertNoOutboundSideEffects();
    }

    private function template(string $design): MessageTemplate
    {
        return MessageTemplate::where('channel', 'EMAIL')->where('slug', str_replace('_', '-', $design))->firstOrFail();
    }

    private function sessionFor(string $design): array
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['name' => 'Jordan Atelier', 'email' => 'jordan.atelier@example.test']);
        $invoice = $design === 'client_invoice' ? Invoice::factory()->create([
            'client_id' => $client->id, 'user_id' => $client->id, 'shoot_id' => null,
            'invoice_number' => 'INV-ATELIER-10482', 'status' => Invoice::STATUS_DRAFT,
            'total' => 369, 'total_amount' => 369, 'subtotal' => 369, 'tax' => 0, 'amount_paid' => 0,
            'issue_date' => '2026-09-13', 'due_date' => '2026-09-27',
        ]) : null;
        $session = AiChatSession::create([
            'user_id' => $admin->id, 'title' => 'Email Atelier real flow regression', 'topic' => 'general',
            'step' => $invoice ? 'send_invoice' : 'send_follow_up',
            'state_data' => $invoice ? ['invoice_id' => $invoice->id] : [
                'client_id' => $client->id, 'follow_up_type' => substr($design, strlen('crm_')),
            ],
        ]);

        return [$session, $client, $invoice];
    }

    private function captureNextEmail(): \stdClass
    {
        $capture = (object) ['payload' => null];
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(function (array $payload) use ($capture): Message {
            $capture->payload = $payload;

            return new Message;
        });
        $this->app->instance(MessagingService::class, $messaging);

        return $capture;
    }

    private function runFlow(string $design, AiChatSession $session): array
    {
        return $design === 'client_invoice'
            ? app(InvoiceBillingFlow::class)->handle($session, 'send invoice')
            : app(ClientCrmFlow::class)->handle($session, 'send follow-up');
    }

    private function assertV6(array $payload, string $design): void
    {
        $this->assertStringContainsString('data-email-design="atelier-v6"', $payload['body_html']);
        $this->assertStringContainsString('data-artwork="'.$design.'"', $payload['body_html']);
        $this->assertSame(1, substr_count($payload['body_html'], 'data-email-content="true"'));
        $this->assertSame(1, preg_match_all('/<!doctype html/i', $payload['body_html']));
        $this->assertStringNotContainsString('{{recipient_name}}', $payload['body_html']);
        $this->assertStringNotContainsString('/studio-assets/', $payload['body_html']);
    }

    private function assertNoOutboundSideEffects(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
