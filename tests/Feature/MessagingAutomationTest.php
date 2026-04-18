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
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use App\Services\Messaging\Providers\LocalSmtpProvider;
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

        $dueTemplate = $this->createTemplate('Invoice Due');
        $overdueTemplate = $this->createTemplate('Invoice Overdue');

        $this->createAutomation('INVOICE_DUE', $dueTemplate, ['client']);
        $this->createAutomation('INVOICE_OVERDUE', $overdueTemplate, ['client']);

        $dueInvoice = $this->createInvoice([
            'client_id' => $client->id,
            'user_id' => $client->id,
            'role' => Invoice::ROLE_CLIENT,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'issue_date' => now()->subDays(2),
            'due_date' => now(),
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
            'issue_date' => now()->subDays(15),
            'due_date' => now()->subDay(),
            'total' => 180,
            'amount_paid' => 0,
            'status' => Invoice::STATUS_SENT,
        ]);

        Artisan::call('messaging:invoice-reminders');

        $dueMessage = Message::where('related_invoice_id', $dueInvoice->id)->first();
        $this->assertNotNull($dueMessage);
        $this->assertSame('AUTOMATION', $dueMessage->send_source);
        $this->assertContains(
            sprintf('INVOICE_DUE:%s:%s', $dueInvoice->id, now()->toDateString()),
            $dueMessage->tags_json ?? []
        );

        $overdueMessage = Message::where('related_invoice_id', $overdueInvoice->id)->first();
        $this->assertNotNull($overdueMessage);
        $this->assertSame('AUTOMATION', $overdueMessage->send_source);
        $this->assertContains(
            sprintf('INVOICE_OVERDUE:%s:%s', $overdueInvoice->id, now()->toDateString()),
            $overdueMessage->tags_json ?? []
        );

        Artisan::call('messaging:invoice-reminders');

        $this->assertSame(1, Message::where('related_invoice_id', $dueInvoice->id)->count());
        $this->assertSame(1, Message::where('related_invoice_id', $overdueInvoice->id)->count());
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
            ->where('tags_json', 'like', '%INVOICE_SUMMARY:client:' . $client->id . '%')
            ->first();
        $this->assertNotNull($clientMessage);

        $repMessage = Message::where('send_source', 'AUTOMATION')
            ->where('tags_json', 'like', '%WEEKLY_REP_INVOICE:rep:' . $rep->id . '%')
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
                        && str_contains($tags[0], 'SHOOT_REMINDER:24H:shoot:' . $shoot->id . ':')
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
                        && str_contains($tags[0], 'SHOOT_REMINDER:24H:shoot:' . $shoot->id . ':')
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
            ->withArgs(fn (string $triggerType, array $context) => $triggerType === 'SHOOT_REMINDER' && !empty($context['shoot_id']))
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
        return AutomationRule::create([
            'name' => $trigger . ' Rule',
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
