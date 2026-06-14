<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

class ManualNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $slug, array $overrides = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'channel' => 'EMAIL',
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => null,
            'category' => 'GENERAL',
            'subject' => 'Subject for ' . $slug,
            'body_html' => '<p>Hello {{recipient_first_name}}</p>',
            'body_text' => 'Hello {{recipient_first_name}}',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function mockMessaging(?array &$captured, string $method = 'sendEmail'): void
    {
        $this->mock(MessagingService::class, function (MockInterface $mock) use (&$captured, $method): void {
            $mock->shouldReceive($method)
                ->once()
                ->withArgs(function (array $payload) use (&$captured): bool {
                    $captured = $payload;

                    return true;
                })
                ->andReturn(Message::make([
                    'channel' => 'EMAIL',
                    'to_address' => 'recipient@example.com',
                    'status' => 'SENT',
                ]));
        });
    }

    public function test_send_dispatches_email_via_mapped_template_with_manual_source(): void
    {
        $this->template('shoot-scheduled');
        $sender = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['email' => 'client@example.com', 'name' => 'Casey Client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        $this->mockMessaging($captured);

        $message = app(ManualNotificationService::class)
            ->send($shoot, 'shoot_scheduled', 'client', 'email', $sender);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('client@example.com', $captured['to']);
        $this->assertSame('MANUAL', $captured['send_source']);
        $this->assertSame($shoot->id, $captured['related_shoot_id']);
        $this->assertSame(MessageTemplate::where('slug', 'shoot-scheduled')->first()->id, $captured['template_id']);

        // AC 12.9 — one audit entry recording sender, shoot, template, recipient, channel, status.
        $entry = UserActivityLog::where('event_type', 'notification.manual_send')->first();
        $this->assertNotNull($entry);
        $this->assertSame($sender->id, $entry->actor_user_id);
        $this->assertSame(Shoot::class, $entry->target_type);
        $this->assertSame($shoot->id, (int) $entry->target_id);
        $this->assertSame('client', $entry->metadata['recipient_type']);
        $this->assertSame('email', $entry->metadata['channel']);
        $this->assertSame('client@example.com', $entry->metadata['recipient']);
    }

    public function test_shoot_ready_records_notified_at_timestamp(): void
    {
        $this->template('shoot-ready');
        $sender = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['email' => 'client@example.com']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'shoot_ready_notified_at' => null]);

        $this->mockMessaging($captured);

        app(ManualNotificationService::class)
            ->send($shoot, 'shoot_ready', 'client', 'email', $sender);

        $this->assertNotNull($shoot->fresh()->shoot_ready_notified_at);
    }

    public function test_payment_due_includes_payment_link_in_rendered_body(): void
    {
        $this->template('payment-due', [
            'body_html' => '<p>Pay here: {{payment_link}}</p>',
            'body_text' => 'Pay here: {{payment_link}}',
        ]);
        $sender = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['email' => 'client@example.com']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        $this->mockMessaging($captured);

        app(ManualNotificationService::class)
            ->send($shoot, 'payment_due', 'client', 'email', $sender);

        $this->assertStringContainsString('/payment/', $captured['body_text']);
    }

    public function test_payment_due_preview_includes_payment_link(): void
    {
        // Req 2.1 — the previewed payment_due body must carry the public payment link,
        // mirroring the send path so an admin sees exactly what will go out.
        MessageTemplate::where('slug', 'payment-due')->delete();
        $this->template('payment-due', [
            'body_html' => '<p>Pay here: {{payment_link}}</p>',
            'body_text' => 'Pay here: {{payment_link}}',
        ]);
        $client = User::factory()->create(['email' => 'client@example.com']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        $preview = app(ManualNotificationService::class)
            ->preview($shoot, 'payment_due', 'client');

        $this->assertStringContainsString('/payment/', (string) $preview['body_text']);
        $this->assertStringContainsString('/payment/', (string) $preview['body_html']);
    }

    public function test_payment_receipt_preview_includes_receipt_details(): void
    {
        // Req 2.2 — the previewed payment_receipt body must carry payment confirmation
        // details (amount paid + remaining balance) derived from completed payments.
        MessageTemplate::where('slug', 'payment-receipt')->delete();
        $this->template('payment-receipt', [
            'body_html' => '<p>Receipt: {{payment_details}}</p>',
            'body_text' => 'Receipt: {{payment_details}}',
        ]);
        $client = User::factory()->create(['email' => 'client@example.com']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        \App\Models\Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'amount'   => 250.50,
            'status'   => \App\Models\Payment::STATUS_COMPLETED,
        ]);

        $preview = app(ManualNotificationService::class)
            ->preview($shoot->fresh(), 'payment_receipt', 'client');

        $body = (string) $preview['body_text'] . "\n" . (string) $preview['body_html'];
        $this->assertStringContainsString('Amount paid: $250.50', $body);
        $this->assertStringContainsString('Remaining balance:', $body);
    }

    public function test_all_six_manual_types_resolve_an_active_seeded_template(): void
    {
        // Req 1.4 — after the system seeder runs, every ManualNotificationService::TYPES
        // entry must resolve to an active SYSTEM template (no missing-template failure for
        // shoot_on_hold / shoot_cancelled / payment_due / payment_receipt).
        app(\Database\Seeders\MessagingSystemSeeder::class)->run();

        $service = app(ManualNotificationService::class);

        foreach (array_keys(ManualNotificationService::TYPES) as $type) {
            $template = $service->resolveTemplate($type);
            $this->assertInstanceOf(MessageTemplate::class, $template, "type {$type} did not resolve a template");
            $this->assertTrue((bool) $template->is_active, "template for type {$type} is not active");
            $this->assertSame(ManualNotificationService::TYPES[$type], $template->slug);
        }
    }

    public function test_send_routes_to_sms_channel_for_photographer(): void
    {
        $this->template('shoot-scheduled', ['channel' => 'SMS', 'body_html' => null]);
        $sender = User::factory()->create(['role' => 'admin']);
        $photographer = User::factory()->create(['phonenumber' => '+15551234567']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);

        $this->mockMessaging($captured, 'sendSms');

        app(ManualNotificationService::class)
            ->send($shoot, 'shoot_scheduled', 'photographer', 'sms', $sender);

        $this->assertSame('+15551234567', $captured['to']);
        $this->assertSame('photographer', $captured['contact_type']);
    }

    public function test_unknown_type_throws(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(ManualNotificationService::class)
            ->send($shoot, 'not_a_type', 'client', 'email', $sender);
    }

    public function test_preview_returns_rendered_subject_and_body_for_known_template(): void
    {
        // Ensure the seeded shoot-scheduled template doesn't shadow our test fixture.
        MessageTemplate::where('slug', 'shoot-scheduled')->delete();
        // Avoid a leading "Hello"/"Hi" greeting — TemplateRenderer strips those as a layout norm.
        $this->template('shoot-scheduled', [
            'subject' => 'Hi {{recipient_first_name}}',
            'body_html' => '<p>Welcome aboard, {{recipient_first_name}}.</p>',
            'body_text' => 'Welcome aboard, {{recipient_first_name}}.',
        ]);
        $client = User::factory()->create(['email' => 'client@example.com', 'name' => 'Casey Client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        $preview = app(ManualNotificationService::class)
            ->preview($shoot, 'shoot_scheduled', 'client');

        $this->assertSame('Hi Casey', $preview['subject']);
        $this->assertStringContainsString('Welcome aboard, Casey', $preview['body_html']);
        $this->assertStringContainsString('Welcome aboard, Casey', $preview['body_text']);
        $this->assertIsArray($preview['missing_variables']);
    }

    public function test_preview_does_not_send_message_or_write_audit_log(): void
    {
        MessageTemplate::where('slug', 'shoot-scheduled')->delete();
        $this->template('shoot-scheduled');
        $client = User::factory()->create(['email' => 'client@example.com']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        // If preview tried to dispatch through MessagingService, the strict mock below would
        // fail the test on an unexpected call.
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendEmail');
            $mock->shouldNotReceive('sendSms');
        });

        app(ManualNotificationService::class)
            ->preview($shoot, 'shoot_scheduled', 'client');

        $this->assertSame(0, Message::count());
        $this->assertSame(0, UserActivityLog::where('event_type', 'notification.manual_send')->count());
    }

    public function test_preview_reports_missing_variables_when_required_var_unresolved(): void
    {
        MessageTemplate::where('slug', 'shoot-scheduled')->delete();
        // Template requires a variable that the resolver cannot fill from the shoot/client.
        $this->template('shoot-scheduled', [
            'variables_json' => ['recipient_first_name', 'mystery_field'],
            'body_html' => '<p>Hello {{recipient_first_name}}, {{mystery_field}}</p>',
            'body_text' => 'Hello {{recipient_first_name}}, {{mystery_field}}',
        ]);
        $client = User::factory()->create(['email' => 'client@example.com', 'name' => 'Casey Client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);

        $preview = app(ManualNotificationService::class)
            ->preview($shoot, 'shoot_scheduled', 'client');

        $this->assertContains('mystery_field', $preview['missing_variables']);
        $this->assertNotContains('recipient_first_name', $preview['missing_variables']);
    }

    public function test_preview_unknown_type_throws(): void
    {
        $shoot = Shoot::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(ManualNotificationService::class)
            ->preview($shoot, 'not_a_type', 'client');
    }
}
