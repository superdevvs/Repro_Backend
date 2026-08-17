<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use Carbon\Carbon;
use Database\Seeders\MessagingSystemSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class MessagingAutomationTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Asserts external-email dispatch and provider-failure handling, so the
        // message has to reach the mocked provider instead of being withheld by
        // the delivery guard. The provider is a double; nothing leaves the
        // process (see MessagingSafetyServiceProvider).
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
    }

    public function test_non_admin_compose_creates_internal_message(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $shoot = Shoot::factory()->create([
            'client_id' => $user->id,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'status' => Shoot::STATUS_SCHEDULED,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Need help',
            'body_text' => 'Hello admin',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ]);

        $response->assertOk();

        $message = Message::first();
        $this->assertNotNull($message);
        $this->assertSame('INTERNAL', $message->provider);
        $this->assertSame('INBOUND', $message->direction);
        $this->assertSame('SENT', $message->status);
        $this->assertSame('MANUAL', $message->send_source);
        $this->assertSame($user->email, $message->from_address);
        $this->assertSame(config('mail.contact_address', 'contact@reprophotos.com'), $message->to_address);
        $this->assertSame($user->id, $message->sender_user_id);
        $this->assertSame($shoot->id, $message->related_shoot_id);
        $this->assertSame('new_shoot', $message->related_shoot_context_type);
        $this->assertSame($user->id, $message->related_account_id);
        $this->assertStringContainsString((string) $user->id, (string) $message->sender_display_name);
    }

    public function test_admin_compose_sends_external_email(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/email/compose', [
            'to' => 'recipient@example.com',
            'subject' => 'Hello',
            'body_text' => 'Testing',
        ]);

        $response->assertOk();

        $message = Message::first();
        $this->assertNotNull($message);
        $this->assertSame('SENT', $message->status);
        $this->assertSame('OUTBOUND', $message->direction);
        $this->assertSame('MANUAL', $message->send_source);
        $this->assertSame('recipient@example.com', $message->to_address);
        $this->assertSame('LOCAL_SMTP', $message->provider);
    }

    public function test_invoice_reminders_send_due_and_overdue_once(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $overdueTemplate = $this->createTemplate('Invoice Overdue');
        $overdueTemplate->forceFill([
            'slug' => 'payment-due-reminder',
            'subject' => 'Payment Reminder - Invoice {{invoice_number}}',
            'body_html' => '<p>Invoice <strong>{{invoice_number}}</strong></p>',
            'body_text' => 'Invoice {{invoice_number}}',
            'variables_json' => ['invoice_number'],
        ])->save();

        $this->createAutomation('INVOICE_OVERDUE', $overdueTemplate, ['client']);

        $dueInvoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'invoice_number' => 'Invoice 00018',
            'issue_date' => now()->subDays(3),
            'due_date' => now()->subDay(),
            'total' => 250,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
        ]);

        $overdueInvoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => now()->subDays(10)->toDateString(),
            'period_end' => now()->subDays(2)->toDateString(),
            'invoice_number' => '00019',
            'issue_date' => now()->subDays(15),
            'due_date' => now()->subDays(2),
            'total' => 180,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
        ]);

        Artisan::call('messaging:invoice-reminders');

        $dueMessage = Message::where('related_invoice_id', $dueInvoice->id)->first();
        $this->assertNotNull($dueMessage);
        $this->assertSame('AUTOMATION', $dueMessage->send_source);
        $this->assertContains(sprintf('INVOICE_OVERDUE:%s:1d', $dueInvoice->id), $dueMessage->tags_json ?? []);
        $this->assertSame('overdue_1d', $dueMessage->metadata['reminder_stage'] ?? null);
        $this->assertSame((float) $dueInvoice->balanceDue(), (float) ($dueMessage->metadata['amount_due'] ?? -1));
        $this->assertSame($dueInvoice->invoice_number, $dueMessage->metadata['invoice_number'] ?? null);
        $this->assertSame($dueInvoice->paymentLink(), $dueMessage->metadata['payment_link'] ?? null);
        $this->assertSame('Payment Reminder - Property details unavailable - Invoice 00018', $dueMessage->subject);
        $this->assertStringContainsString('Invoice Number: 00018', html_entity_decode(strip_tags($dueMessage->body_html)));
        $this->assertStringContainsString('Invoice Number: 00018', $dueMessage->body_text);
        $this->assertStringNotContainsString('Invoice Invoice', $dueMessage->subject.$dueMessage->body_html.$dueMessage->body_text);

        $overdueMessage = Message::where('related_invoice_id', $overdueInvoice->id)->first();
        $this->assertNotNull($overdueMessage);
        $this->assertSame('AUTOMATION', $overdueMessage->send_source);
        $this->assertContains(sprintf('INVOICE_OVERDUE:%s:2d', $overdueInvoice->id), $overdueMessage->tags_json ?? []);
        $this->assertSame('overdue_2d', $overdueMessage->metadata['reminder_stage'] ?? null);
        $this->assertSame($overdueInvoice->invoice_number, $overdueMessage->metadata['invoice_number'] ?? null);
        $this->assertSame($overdueInvoice->paymentLink(), $overdueMessage->metadata['payment_link'] ?? null);
        $this->assertSame('Payment Reminder - Property details unavailable - Invoice 00019', $overdueMessage->subject);
        $this->assertStringContainsString('Invoice Number: 00019', html_entity_decode(strip_tags($overdueMessage->body_html)));
        $this->assertStringContainsString('Invoice Number: 00019', $overdueMessage->body_text);
        $this->assertStringNotContainsString('Invoice Invoice', $overdueMessage->subject.$overdueMessage->body_html.$overdueMessage->body_text);

        Artisan::call('messaging:invoice-reminders');

        $this->assertSame(1, Message::where('related_invoice_id', $dueInvoice->id)->count());
        $this->assertSame(1, Message::where('related_invoice_id', $overdueInvoice->id)->count());
    }

    public function test_invoice_reminders_resolve_direct_and_unique_related_property_context(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'property-client@example.com']);
        $template = $this->createTemplate('Invoice Property Reminder');
        $template->forceFill([
            'slug' => 'payment-due-reminder-property-context',
            'subject' => 'Payment Reminder - {{shoot_location}} - Invoice {{invoice_number}}',
            'body_html' => '<p>Location: {{shoot_location}}</p><p>Address: {{shoot_address}}</p>',
            'body_text' => "Location: {{shoot_location}}\nAddress: {{shoot_address}}",
            'variables_json' => ['invoice_number', 'shoot_location', 'shoot_address'],
        ])->save();
        $this->createAutomation('INVOICE_OVERDUE', $template, ['client']);

        $directShoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'address' => '421 Direct Avenue',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
        ]);
        $directInvoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'shoot_id' => $directShoot->id,
            'invoice_number' => 'Invoice 00421',
            'due_date' => now()->subDay(),
        ]);

        $uniqueShoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'address' => '422 Unique Lane',
            'city' => 'Orlando',
            'state' => 'FL',
            'zip' => '32801',
        ]);
        $uniqueInvoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'shoot_id' => null,
            'invoice_number' => 'Invoice 00422',
            'due_date' => now()->subDay(),
        ]);
        $uniqueInvoice->shoots()->attach($uniqueShoot->id);
        $uniqueInvoice->items()->create([
            'shoot_id' => $uniqueShoot->id,
            'type' => 'charge',
            'description' => 'Photography',
            'quantity' => 1,
            'unit_amount' => 100,
            'total_amount' => 100,
        ]);

        Artisan::call('messaging:invoice-reminders');

        $directMessage = Message::where('related_invoice_id', $directInvoice->id)->firstOrFail();
        $this->assertSame('Payment Reminder - 421 Direct Avenue, Tampa, FL, 33602 - Invoice 00421', $directMessage->subject);
        $this->assertSame(1, substr_count($directMessage->subject, 'Invoice'));
        $this->assertSame($directShoot->id, $directMessage->related_shoot_id);
        $this->assertSame($directShoot->id, $directMessage->metadata['shoot_id'] ?? null);
        $this->assertStringContainsString('421 Direct Avenue, Tampa, FL, 33602', $directMessage->body_html);
        $this->assertStringContainsString('421 Direct Avenue, Tampa, FL, 33602', $directMessage->body_text);

        $uniqueMessage = Message::where('related_invoice_id', $uniqueInvoice->id)->firstOrFail();
        $this->assertSame('Payment Reminder - 422 Unique Lane, Orlando, FL, 32801 - Invoice 00422', $uniqueMessage->subject);
        $this->assertSame(1, substr_count($uniqueMessage->subject, 'Invoice'));
        $this->assertSame($uniqueShoot->id, $uniqueMessage->related_shoot_id);
        $this->assertSame($uniqueShoot->id, $uniqueMessage->metadata['shoot_id'] ?? null);
        $this->assertStringContainsString('422 Unique Lane, Orlando, FL, 32801', $uniqueMessage->body_html);
        $this->assertStringContainsString('422 Unique Lane, Orlando, FL, 32801', $uniqueMessage->body_text);
    }

    public function test_invoice_reminder_uses_multiple_properties_fallback_when_context_is_ambiguous(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'multi-property-client@example.com']);
        $template = $this->createTemplate('Multi-property Invoice Reminder');
        $template->forceFill([
            'slug' => 'payment-due-reminder-multiple-properties',
            'subject' => 'Payment Reminder - {{shoot_location}} - Invoice {{invoice_number}}',
            'body_html' => '<p>Location: {{shoot_location}}</p><p>Address: {{shoot_address}}</p>',
            'body_text' => "Location: {{shoot_location}}\nAddress: {{shoot_address}}",
            'variables_json' => ['invoice_number', 'shoot_location', 'shoot_address'],
        ])->save();
        $this->createAutomation('INVOICE_OVERDUE', $template, ['client']);

        $shoots = Shoot::factory()->count(2)->create(['client_id' => $client->id]);
        $invoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'shoot_id' => null,
            'invoice_number' => 'Invoice 00423',
            'due_date' => now()->subDay(),
        ]);
        $invoice->shoots()->attach($shoots->modelKeys());

        Artisan::call('messaging:invoice-reminders');

        $message = Message::where('related_invoice_id', $invoice->id)->firstOrFail();
        $visibleHtml = html_entity_decode(strip_tags($message->body_html));

        $this->assertSame('Payment Reminder - Multiple properties - Invoice 00423', $message->subject);
        $this->assertSame(1, substr_count($message->subject, 'Invoice'));
        $this->assertNull($message->related_shoot_id);
        $this->assertSame(2, $message->metadata['related_shoot_count'] ?? null);
        $this->assertSame('Multiple properties', $message->metadata['shoot_location'] ?? null);
        $this->assertGreaterThanOrEqual(2, substr_count($visibleHtml, 'Multiple properties'));
        $this->assertStringContainsString('Multiple properties', $message->body_text);
        foreach ($shoots as $shoot) {
            $this->assertStringNotContainsString(
                $shoot->address,
                $message->subject.$message->body_html.$message->body_text
            );
        }
    }

    public function test_invoice_overdue_only_fires_on_scheduled_offsets(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'overdue-client@example.com']);
        $overdueTemplate = $this->createTemplate('Invoice Overdue Cadence');
        $this->createAutomation('INVOICE_OVERDUE', $overdueTemplate, ['client']);

        $scheduledOffsets = [1, 2, 3, 7, 30, 60, 90];
        $skippedOffsets = [4, 5, 6, 8, 15, 29, 31, 45, 59, 61];

        $scheduledInvoices = [];
        foreach ($scheduledOffsets as $offset) {
            $scheduledInvoices[$offset] = $this->createInvoice([
                'client_id' => $client->id,
                'user_id' => $client->id,
                'role' => Invoice::ROLE_CLIENT,
                'invoice_number' => 'INV-S-'.$offset,
                'issue_date' => now()->subDays($offset + 5),
                'due_date' => now()->subDays($offset),
                'total' => 200 + $offset,
                'amount_paid' => 0,
            ]);
        }

        $skippedInvoices = [];
        foreach ($skippedOffsets as $offset) {
            $skippedInvoices[$offset] = $this->createInvoice([
                'client_id' => $client->id,
                'user_id' => $client->id,
                'role' => Invoice::ROLE_CLIENT,
                'invoice_number' => 'INV-K-'.$offset,
                'issue_date' => now()->subDays($offset + 5),
                'due_date' => now()->subDays($offset),
                'total' => 200 + $offset,
                'amount_paid' => 0,
            ]);
        }

        Artisan::call('messaging:invoice-reminders');

        foreach ($scheduledInvoices as $offset => $invoice) {
            $messages = Message::where('related_invoice_id', $invoice->id)->get();
            $this->assertCount(
                1,
                $messages,
                sprintf('Expected one overdue reminder at offset %d', $offset)
            );
            $this->assertContains(
                sprintf('INVOICE_OVERDUE:%s:%dd', $invoice->id, $offset),
                $messages->first()->tags_json ?? []
            );
            $this->assertSame(sprintf('overdue_%dd', $offset), $messages->first()->metadata['reminder_stage'] ?? null);
        }

        foreach ($skippedInvoices as $offset => $invoice) {
            $this->assertSame(
                0,
                Message::where('related_invoice_id', $invoice->id)->count(),
                sprintf('Expected no reminder at offset %d', $offset)
            );
        }

        Artisan::call('messaging:invoice-reminders');

        foreach ($scheduledInvoices as $offset => $invoice) {
            $this->assertSame(
                1,
                Message::where('related_invoice_id', $invoice->id)->count(),
                sprintf('Idempotency violated for offset %d', $offset)
            );
        }
    }

    public function test_invoice_reminders_skip_non_client_role_invoices(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $photographer = User::factory()->create(['role' => 'photographer', 'email' => 'photog@example.com']);
        $overdueTemplate = $this->createTemplate('Invoice Overdue Photog');
        $this->createAutomation('INVOICE_OVERDUE', $overdueTemplate, ['client']);

        $photographerInvoice = $this->createInvoice([
            'client_id' => null,
            'user_id' => $photographer->id,
            'role' => Invoice::ROLE_PHOTOGRAPHER,
            'photographer_id' => $photographer->id,
            'issue_date' => now()->subDays(10),
            'due_date' => now()->subDay(),
            'total' => 300,
            'amount_paid' => 0,
        ]);

        Artisan::call('messaging:invoice-reminders');

        $this->assertSame(
            0,
            Message::where('related_invoice_id', $photographerInvoice->id)->count(),
            'Photographer payout invoices must not receive client overdue reminders.'
        );
    }

    public function test_invoice_reminders_stop_once_balance_is_paid(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'paid-stop@example.com']);
        $template = $this->createTemplate('Invoice Overdue Paid Stop');
        $this->createAutomation('INVOICE_OVERDUE', $template, ['client']);

        $invoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'invoice_number' => 'INV-PAID',
            'issue_date' => now()->subDays(8),
            'due_date' => now()->subDays(7),
            'total' => 300,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
        ]);

        Artisan::call('messaging:invoice-reminders');
        $this->assertSame(1, Message::where('related_invoice_id', $invoice->id)->count());

        $invoice->forceFill([
            'amount_paid' => 300,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ])->save();

        Artisan::call('messaging:invoice-reminders');
        $this->assertSame(1, Message::where('related_invoice_id', $invoice->id)->count());
    }

    public function test_system_seeder_sets_invoice_reminder_automation_templates(): void
    {
        app(MessagingSystemSeeder::class)->run();

        $invoiceDue = AutomationRule::query()
            ->where('trigger_type', 'INVOICE_DUE')
            ->where('name', 'Invoice Due Reminder')
            ->first();

        $invoiceOverdue = AutomationRule::query()
            ->where('trigger_type', 'INVOICE_OVERDUE')
            ->where('name', 'Invoice Overdue Reminder')
            ->first();

        $template = MessageTemplate::query()
            ->where('slug', 'payment-due-reminder')
            ->first();

        $this->assertNotNull($invoiceDue);
        $this->assertNotNull($invoiceOverdue);
        $this->assertNotNull($template);
        $this->assertSame($template->id, $invoiceDue->template_id);
        $this->assertSame($template->id, $invoiceOverdue->template_id);
    }

    public function test_weekly_invoice_summaries_send_for_clients_and_reps(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $rep = User::factory()->create(['role' => 'salesRep', 'email' => 'rep@example.com']);

        $summaryTemplate = $this->createTemplate('Invoice Summary');
        $repTemplate = $this->createTemplate('Rep Invoice Summary');

        $this->createAutomation('INVOICE_SUMMARY', $summaryTemplate, ['client']);
        $this->createAutomation('WEEKLY_REP_INVOICE', $repTemplate, ['rep']);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'rep_id' => $rep->id,
            'scheduled_date' => now()->subDays(10),
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
        ]);

        [$start, $end] = $this->lastCompletedWeek();

        $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'issue_date' => $start->copy()->addDay(),
            'due_date' => $end->copy()->addDays(7),
            'total' => 300,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
            'shoot_id' => $shoot->id,
        ]);

        Artisan::call('messaging:invoice-summaries');

        $clientMessage = Message::where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%INVOICE_SUMMARY:client:'.$client->id.'%')
            ->first();
        $this->assertNotNull($clientMessage);

        $repMessage = Message::where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%WEEKLY_REP_INVOICE:rep:'.$rep->id.'%')
            ->first();
        $this->assertNotNull($repMessage);
    }

    public function test_account_verified_event_triggers_automation(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create(['role' => 'client']);
        $user->forceFill(['email_verified_at' => now()])->save();

        $template = $this->createTemplate('Account Verified');
        $this->createAutomation('ACCOUNT_VERIFIED', $template, ['client']);

        event(new Verified($user));

        $message = Message::where('send_source', 'AUTOMATION')
            ->where('related_account_id', $user->id)
            ->first();

        $this->assertNotNull($message);
    }

    public function test_system_seeder_sets_shoot_booked_recipients_to_photographer_only(): void
    {
        app(MessagingSystemSeeder::class)->run();

        $rule = AutomationRule::query()
            ->where('trigger_type', 'SHOOT_BOOKED')
            ->where('name', 'Shoot Booking Confirmation')
            ->first();

        $this->assertNotNull($rule);
        $this->assertSame(['photographer'], $rule->recipients_json);
    }

    public function test_send_email_marks_message_failed_when_provider_throws(): void
    {
        $this->createDefaultEmailChannel();

        $provider = Mockery::mock(LocalSmtpProvider::class);
        $provider->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->app->instance(LocalSmtpProvider::class, $provider);

        $service = $this->app->make(MessagingService::class);

        try {
            $service->sendEmail([
                'to' => 'recipient@example.com',
                'subject' => 'Test message',
                'body_html' => '<p>Hello</p>',
                'body_text' => 'Hello',
            ]);

            $this->fail('Expected provider exception to bubble up.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $message = Message::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('FAILED', $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertSame('SMTP unavailable', $message->error_message);
    }

    public function test_dispatch_scheduled_message_marks_message_failed_when_provider_throws(): void
    {
        $this->createDefaultEmailChannel();

        $provider = Mockery::mock(LocalSmtpProvider::class);
        $provider->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->app->instance(LocalSmtpProvider::class, $provider);

        $service = $this->app->make(MessagingService::class);
        $message = $service->scheduleEmail([
            'to' => 'recipient@example.com',
            'subject' => 'Scheduled test',
            'body_html' => '<p>Hello later</p>',
            'body_text' => 'Hello later',
        ], now()->addMinute());

        try {
            $service->dispatchScheduledMessage($message->fresh());

            $this->fail('Expected scheduled provider exception to bubble up.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP unavailable', $exception->getMessage());
        }

        $message->refresh();
        $this->assertSame('FAILED', $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertSame('SMTP unavailable', $message->error_message);
    }

    public function test_retry_stuck_command_sends_eligible_queued_email_messages(): void
    {
        $channel = $this->createDefaultEmailChannel();

        $provider = Mockery::mock(LocalSmtpProvider::class);
        $provider->shouldReceive('send')
            ->once()
            ->andReturn('provider-retry-id');
        $this->app->instance(LocalSmtpProvider::class, $provider);

        $message = $this->createQueuedEmailMessage($channel, [
            'to_address' => 'retry-success@example.com',
        ]);

        Artisan::call('messages:retry-stuck', [
            '--minutes' => 5,
            '--max-attempts' => 3,
            '--limit' => 10,
        ]);

        $message->refresh();

        $this->assertSame('SENT', $message->status);
        $this->assertSame('provider-retry-id', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
        $this->assertSame(1, $message->metadata['retry_stuck_attempts'] ?? null);
        $this->assertNotNull($message->metadata['retry_stuck_recovered_at'] ?? null);
    }

    public function test_retry_stuck_command_keeps_retryable_failures_queued_for_future_attempts(): void
    {
        $channel = $this->createDefaultEmailChannel();

        $provider = Mockery::mock(LocalSmtpProvider::class);
        $provider->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('Temporary SMTP outage'));
        $this->app->instance(LocalSmtpProvider::class, $provider);

        $message = $this->createQueuedEmailMessage($channel, [
            'to_address' => 'retry-pending@example.com',
        ]);

        Artisan::call('messages:retry-stuck', [
            '--minutes' => 5,
            '--max-attempts' => 3,
            '--limit' => 10,
        ]);

        $message->refresh();

        $this->assertSame('QUEUED', $message->status);
        $this->assertNull($message->failed_at);
        $this->assertNull($message->error_message);
        $this->assertSame(1, $message->metadata['retry_stuck_attempts'] ?? null);
        $this->assertSame('Temporary SMTP outage', $message->metadata['retry_stuck_last_error'] ?? null);
    }

    public function test_retry_stuck_command_marks_exhausted_messages_failed(): void
    {
        $channel = $this->createDefaultEmailChannel();

        $provider = Mockery::mock(LocalSmtpProvider::class);
        $provider->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('Permanent SMTP outage'));
        $this->app->instance(LocalSmtpProvider::class, $provider);

        $message = $this->createQueuedEmailMessage($channel, [
            'to_address' => 'retry-failed@example.com',
        ]);

        Artisan::call('messages:retry-stuck', [
            '--minutes' => 5,
            '--max-attempts' => 1,
            '--limit' => 10,
        ]);

        $message->refresh();

        $this->assertSame('FAILED', $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertSame('Permanent SMTP outage', $message->error_message);
        $this->assertSame(1, $message->metadata['retry_stuck_attempts'] ?? null);
        $this->assertSame('Permanent SMTP outage', $message->metadata['retry_stuck_last_error'] ?? null);
    }

    public function test_retry_stuck_command_dry_run_does_not_mutate_messages(): void
    {
        $channel = $this->createDefaultEmailChannel();

        $provider = Mockery::mock(LocalSmtpProvider::class);
        $provider->shouldReceive('send')->never();
        $this->app->instance(LocalSmtpProvider::class, $provider);

        $message = $this->createQueuedEmailMessage($channel, [
            'to_address' => 'retry-dry-run@example.com',
        ]);

        Artisan::call('messages:retry-stuck', [
            '--minutes' => 5,
            '--max-attempts' => 3,
            '--limit' => 10,
            '--dry-run' => true,
        ]);

        $message->refresh();

        $this->assertSame('QUEUED', $message->status);
        $this->assertNull($message->sent_at);
        $this->assertNull($message->failed_at);
        $this->assertNull($message->metadata);
    }

    public function test_shoot_reminders_only_fall_back_for_client_when_automation_already_notified_photographer(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 18, 12, 0, 0));
        try {
            $client = User::factory()->create(['role' => 'client', 'email' => 'reminder-client@example.com']);
            $photographer = User::factory()->create(['role' => 'photographer', 'email' => 'reminder-photographer@example.com']);
            $shoot = $this->createReminderShoot($client, $photographer);

            $mailService = Mockery::mock(MailService::class);
            $mailService->shouldReceive('sendShootReminderEmail')
                ->once()
                ->withArgs(function (
                    User $recipient,
                    Shoot $targetShoot,
                    $scheduledAt,
                    array $tags,
                    ?bool $notifyPhotographer = null
                ) use ($client, $shoot) {
                    return $recipient->is($client)
                        && $targetShoot->id === $shoot->id
                        && $scheduledAt instanceof Carbon
                        && count($tags) === 1
                        && str_contains($tags[0], 'SHOOT_REMINDER:24H:shoot:'.$shoot->id.':')
                        && $notifyPhotographer === false;
                })
                ->andReturnTrue();
            $this->app->instance(MailService::class, $mailService);

            $this->app->instance(
                AutomationService::class,
                $this->buildReminderAutomationServiceDouble($mailService, [
                    'trigger_type' => 'SHOOT_REMINDER',
                    'active_rule_count' => 1,
                    'handled' => true,
                    'client_email_sent' => false,
                    'photographer_email_sent' => true,
                ])
            );

            app(AutomationService::class)->triggerShootReminders();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_shoot_reminders_only_fall_back_for_photographer_when_automation_already_notified_client(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 18, 12, 0, 0));
        try {
            $client = User::factory()->create(['role' => 'client', 'email' => 'reminder-client-2@example.com']);
            $photographer = User::factory()->create(['role' => 'photographer', 'email' => 'reminder-photographer-2@example.com']);
            $shoot = $this->createReminderShoot($client, $photographer);

            $mailService = Mockery::mock(MailService::class);
            $mailService->shouldReceive('sendShootReminderEmail')
                ->once()
                ->withArgs(function (
                    User $recipient,
                    Shoot $targetShoot,
                    $scheduledAt,
                    array $tags,
                    ?bool $notifyPhotographer = null
                ) use ($photographer, $shoot) {
                    return $recipient->is($photographer)
                        && $targetShoot->id === $shoot->id
                        && $scheduledAt instanceof Carbon
                        && count($tags) === 1
                        && str_contains($tags[0], 'SHOOT_REMINDER:24H:shoot:'.$shoot->id.':')
                        && $notifyPhotographer === false;
                })
                ->andReturnTrue();
            $this->app->instance(MailService::class, $mailService);

            $this->app->instance(
                AutomationService::class,
                $this->buildReminderAutomationServiceDouble($mailService, [
                    'trigger_type' => 'SHOOT_REMINDER',
                    'active_rule_count' => 1,
                    'handled' => true,
                    'client_email_sent' => true,
                    'photographer_email_sent' => false,
                ])
            );

            app(AutomationService::class)->triggerShootReminders();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createDefaultEmailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }

    private function createQueuedEmailMessage(MessageChannel $channel, array $overrides = []): Message
    {
        $message = Message::create(array_merge([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => $channel->provider,
            'message_channel_id' => $channel->id,
            'from_address' => $channel->from_email,
            'to_address' => 'queued@example.com',
            'subject' => 'Queued retry target',
            'body_text' => 'Queued retry target',
            'body_html' => '<p>Queued retry target</p>',
            'status' => 'QUEUED',
            'send_source' => 'AUTOMATION',
        ], $overrides));

        $message->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        return $message;
    }

    private function createReminderShoot(User $client, User $photographer): Shoot
    {
        $scheduledAt = now()->addHours(24);

        return Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'scheduled_date' => $scheduledAt->toDateString(),
            'time' => $scheduledAt->format('H:i'),
        ]);
    }

    private function buildReminderAutomationServiceDouble(MailService $mailService, array $dispatchResult): AutomationService
    {
        $template = $this->createTemplate('Shoot Reminder');
        $this->createAutomation('SHOOT_REMINDER', $template, ['client', 'photographer']);

        $workflowExecutor = Mockery::mock(AutomationWorkflowExecutor::class);
        $workflowExecutor->shouldReceive('executeEventTrigger')
            ->once()
            ->withArgs(fn (string $triggerType, array $context) => $triggerType === 'SHOOT_REMINDER' && ! empty($context['shoot_id']))
            ->andReturn($dispatchResult);

        return new AutomationService(
            $this->app->make(MessagingService::class),
            $this->app->make(TemplateRenderer::class),
            $this->app->make(TemplateVariableResolver::class),
            $workflowExecutor,
            $mailService
        );
    }

    private function createTemplate(string $name): MessageTemplate
    {
        return MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => $name,
            'subject' => $name,
            'body_text' => 'Hello {{client_name}}',
            'variables_json' => ['client_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    private function createAutomation(string $trigger, MessageTemplate $template, array $recipients): AutomationRule
    {
        $existingRule = AutomationRule::query()
            ->where('trigger_type', $trigger)
            ->orderBy('id')
            ->first();

        if ($existingRule) {
            $existingRule->forceFill([
                'template_id' => $template->id,
                'is_active' => true,
                'recipients_json' => $recipients,
            ])->save();

            AutomationRule::query()
                ->where('trigger_type', $trigger)
                ->where('id', '!=', $existingRule->id)
                ->update(['is_active' => false]);

            return $existingRule->fresh();
        }

        return AutomationRule::create([
            'name' => $trigger.' Rule',
            'trigger_type' => $trigger,
            'template_id' => $template->id,
            'is_active' => true,
            'scope' => 'GLOBAL',
            'recipients_json' => $recipients,
        ]);
    }

    private function createInvoice(array $overrides): Invoice
    {
        $defaults = [
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'role' => Invoice::ROLE_CLIENT,
            'status' => Invoice::STATUS_SENT,
            'total' => 100,
            'amount_paid' => 0,
            'issue_date' => now()->subDays(5),
            'due_date' => now()->addDays(10),
            'invoice_number' => 'INV-'.strtoupper(\Illuminate\Support\Str::random(6)),
        ];

        return Invoice::create(array_merge($defaults, $overrides));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function lastCompletedWeek(): array
    {
        $end = now()->startOfWeek()->subDay()->endOfDay();
        $start = $end->copy()->startOfWeek();

        return [$start, $end];
    }
}
