<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingService;
use App\Services\SystemEmails\BookingInvoiceContext;
use App\Services\SystemEmails\EmailPreviewContext;
use App\Services\SystemEmails\SystemEmailBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ClientWorkflowEmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    public static function bookingTriggers(): array
    {
        return [['SHOOT_SCHEDULED'], ['SHOOT_BOOKED']];
    }

    #[DataProvider('bookingTriggers')]
    public function test_configured_booking_email_keeps_saved_copy_and_attaches_only_the_clients_invoice(string $trigger): void
    {
        [$shoot, $invoice, $rule] = $this->bookingFixture($trigger, ['client', 'photographer']);
        $this->recordConfiguredEmails();
        $service = app(AutomationService::class);
        $context = $service->buildShootContext($shoot) + ['automation_rule_id' => $rule->id];
        $service->handleEvent($trigger, $context);
        $service->handleEvent($trigger, $context);

        $this->assertSame(2, Message::count());
        $message = Message::where('to_address', $shoot->client->email)->firstOrFail();
        $this->assertSame($invoice->id, $message->related_invoice_id);
        $this->assertSame($shoot->client_id, $message->related_account_id);
        $this->assertSame('Authored booking subject', $message->subject);
        $this->assertStringContainsString('Our authored booking introduction.', $message->body_html);
        $this->assertStringContainsString($invoice->invoice_number, $message->body_html);
        $this->assertStringContainsString('View Invoice', $message->body_html);
        $this->assertStringNotContainsString('Pay Now', $message->body_html);
        $this->assertStringContainsString(app(MailService::class)->generatePaymentLink($shoot), $message->body_html);
        $this->assertStringContainsString('/shoots/'.$shoot->id, $message->body_html);
        $staffMessage = Message::where('to_address', $shoot->photographer->email)->firstOrFail();
        $this->assertNull($staffMessage->related_invoice_id);
        $this->assertStringNotContainsString($invoice->invoice_number, $staffMessage->body_html);
    }

    public function test_configured_booking_email_rejects_stale_client_and_foreign_invoice_contexts(): void
    {
        [$shoot, , $rule] = $this->bookingFixture('SHOOT_SCHEDULED');
        $this->recordConfiguredEmails();
        $service = app(AutomationService::class);
        $other = User::factory()->create(['role' => 'client']);
        $context = array_replace($service->buildShootContext($shoot), [
            'automation_rule_id' => $rule->id, 'account_id' => $other->id, 'client' => $other,
        ]);
        $service->handleEvent('SHOOT_SCHEDULED', $context);
        $this->assertSame(0, Message::count());

        $otherShoot = Shoot::withoutEvents(fn () => Shoot::factory()->create(['client_id' => $other->id]));
        $foreignInvoice = app(InvoiceService::class)->generateForShoot($otherShoot);
        $service->handleEvent('SHOOT_SCHEDULED', $service->buildShootContext($shoot) + [
            'automation_rule_id' => $rule->id, 'invoice_id' => $foreignInvoice->id,
        ]);
        $this->assertSame(0, Message::count());
    }

    public function test_paid_configured_booking_links_to_the_shoot_and_legacy_sender_uses_the_same_invoice_context(): void
    {
        [$shoot, , $rule] = $this->bookingFixture('SHOOT_SCHEDULED');
        Payment::withoutEvents(fn () => Payment::factory()->create([
            'shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 200,
        ]));
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $this->recordConfiguredEmails();
        $service = app(AutomationService::class);
        $context = $service->buildShootContext($shoot);
        $service->handleEvent('SHOOT_SCHEDULED', $context + ['automation_rule_id' => $rule->id]);
        $message = Message::firstOrFail();
        $this->assertSame($invoice->id, $message->related_invoice_id);
        $this->assertStringContainsString('Paid', $message->body_html);
        $this->assertStringContainsString('/shoots/'.$shoot->id, $message->body_html);
        $this->assertStringNotContainsString('/payment/', $message->body_html);

        $legacy = new ReflectionMethod(AutomationService::class, 'sendMessage');
        $legacy->invoke($service, $rule, ['type' => 'client', 'email' => $shoot->client->email, 'name' => $shoot->client->name], $context);
        $this->assertSame(2, Message::count());
        $this->assertSame($invoice->id, Message::latest('id')->first()->related_invoice_id);
        $this->assertStringContainsString('View Invoice', Message::latest('id')->first()->body_html);
    }

    private function bookingFixture(string $trigger, array $recipients = ['client']): array
    {
        config(['app.frontend_url' => 'https://reprodashboard.com']);
        AutomationRule::query()->update(['is_active' => false]);
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'base_quote' => 200, 'tax_amount' => 0, 'total_quote' => 200, 'payment_status' => 'unpaid',
        ]));
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $template = MessageTemplate::create([
            'name' => 'Authored booking', 'channel' => 'EMAIL', 'scope' => 'SYSTEM', 'is_active' => true,
            'subject' => 'Authored booking subject',
            'body_html' => '<p>Our authored booking introduction.</p><p>{{shoot_location}}</p>{{payment_cta_html}}<a href="{{dashboard_link}}">View Shoot</a>',
            'body_text' => 'Our authored booking introduction. {{payment_cta_text}}', 'variables_json' => [],
        ]);
        $rule = AutomationRule::create([
            'name' => 'Configured booking', 'trigger_type' => $trigger, 'scope' => 'SYSTEM',
            'is_active' => true, 'template_id' => $template->id, 'recipients_json' => $recipients,
        ]);

        return [$shoot->fresh(['client', 'photographer']), $invoice, $rule];
    }

    private function recordConfiguredEmails(): void
    {
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->andReturnUsing(fn (array $payload) => Message::create([
            'channel' => 'EMAIL', 'direction' => 'OUTBOUND', 'provider' => 'FAKE', 'status' => 'SENT',
            'send_source' => 'AUTOMATION', 'to_address' => $payload['to'], 'subject' => $payload['subject'],
            'body_html' => $payload['body_html'], 'body_text' => $payload['body_text'],
            'tags_json' => $payload['tags_json'] ?? [], 'related_invoice_id' => $payload['related_invoice_id'] ?? null,
            'related_shoot_id' => $payload['related_shoot_id'] ?? null, 'related_account_id' => $payload['related_account_id'] ?? null,
        ]));
        $this->app->instance(MessagingService::class, $messaging);
    }

    public function test_delivery_subject_identifies_the_property_and_state(): void
    {
        $payload = EmailPreviewContext::protectedPayload('SHOOT_DELIVERED');
        $payload['shoot']['location'] = '509 Foster Knoll Drive, Joppa, MD 21085';
        $rendered = app(SystemEmailBuilder::class)->build('SHOOT_DELIVERED', $payload);

        $this->assertSame('Photos Ready - Balance Due - 509 Foster Knoll Drive, Joppa, MD 21085', $rendered['subject']);
        $this->assertStringContainsString('509 Foster Knoll Drive, Joppa, MD 21085', $rendered['body_html']);
    }

    public function test_a_stale_recipient_cannot_receive_another_clients_delivery_or_summary(): void
    {
        $shoot = Shoot::factory()->create();
        $otherClient = User::factory()->create(['role' => 'client']);
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldNotReceive('sendEmail');
        $this->app->instance(MessagingService::class, $messaging);

        $this->assertFalse(app(MailService::class)->sendShootReadyEmail($otherClient, $shoot));
        $this->assertFalse(app(MailService::class)->sendShootSummaryEmail($otherClient, $shoot));
    }

    public function test_booking_email_contains_the_clients_invoice_and_a_property_specific_link(): void
    {
        config(['app.frontend_url' => 'https://reprodashboard.com']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'base_quote' => 200, 'total_quote' => 200, 'payment_status' => 'unpaid',
        ]);
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        MessageTemplate::where('email_type', 'SHOOT_SCHEDULED')->update(['override_enabled' => false]);
        MessageTemplate::create([
            'name' => 'Booking copy', 'slug' => 'booking-copy-identity-test', 'channel' => 'EMAIL',
            'scope' => 'SYSTEM', 'is_active' => true, 'email_type' => 'SHOOT_SCHEDULED', 'override_enabled' => true,
            'subject' => 'Scheduled: {{shoot_location}}',
            'body_html' => '<p>Our saved booking introduction.</p><p>{{shoot_location}}</p>{{payment_cta_html}}<p><a href="{{dashboard_url}}">View Shoot</a></p>',
            'body_text' => '{{payment_cta_text}}', 'variables_json' => [],
        ]);
        $sent = [];
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()->andReturnUsing(function (array $payload) use (&$sent) {
            $sent = $payload;

            return new Message;
        });
        $this->app->instance(MessagingService::class, $messaging);

        $this->assertTrue(app(MailService::class)->sendShootScheduledEmail($client, $shoot, 'https://reprodashboard.com/payment/example', false));
        $this->assertSame($client->email, $sent['to']);
        $this->assertSame($invoice->id, $sent['related_invoice_id']);
        $this->assertStringContainsString($invoice->invoice_number, $sent['body_html']);
        $this->assertStringContainsString('View Invoice', $sent['body_html']);
        $this->assertStringContainsString('Our saved booking introduction.', $sent['body_html']);
        $this->assertStringContainsString('https://reprodashboard.com/shoots/'.$shoot->id, $sent['body_html']);

        $invoice->update(['client_id' => User::factory()->create(['role' => 'client'])->id]);
        $this->assertSame([], app(BookingInvoiceContext::class)->forClient($shoot, $client));
    }
}
