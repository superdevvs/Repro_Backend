<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use App\Services\SystemEmails\EmailContextBuilder;
use App\Services\SystemEmails\SystemEmailOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SystemEmailPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_service_records_canonical_dispatch_and_deduplicates_account_created_email(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'canonical-client@example.com',
            'name' => 'Canonical Client',
        ]);

        $service = $this->app->make(MailService::class);
        $resetLink = 'https://reprodashboard.com/reset-password?token=test-token&email=' . urlencode($user->email);

        $this->assertTrue($service->sendAccountCreatedEmail($user, $resetLink));
        $this->assertTrue($service->sendAccountCreatedEmail($user, $resetLink));

        $message = Message::query()->where('related_account_id', $user->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('ACCOUNT_CREATED', $message->send_source);
        $this->assertSame('ACCOUNT_CREATED', $message->metadata['canonical_email']['email_alias'] ?? null);
        $this->assertSame('ACCOUNT_CREATED_V1', $message->metadata['canonical_email']['email_type'] ?? null);

        $this->assertSame(1, Message::query()->where('related_account_id', $user->id)->count());
        $this->assertSame(1, SystemEmailDispatch::query()->where('email_alias', 'ACCOUNT_CREATED')->count());

        $dispatch = SystemEmailDispatch::query()->where('email_alias', 'ACCOUNT_CREATED')->first();
        $this->assertNotNull($dispatch);
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame($message->id, $dispatch->message_id);
    }

    public function test_protected_automation_ignores_legacy_template_html_and_uses_canonical_pipeline(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'protected-automation@example.com',
            'name' => 'Protected Automation',
        ]);

        $template = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Legacy Account Created Override',
            'subject' => 'Legacy Account Created Override',
            'body_html' => '<p>LEGACY HTML SHOULD NOT SHIP</p>',
            'body_text' => 'LEGACY TEXT SHOULD NOT SHIP',
            'variables_json' => ['client_name'],
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);

        AutomationRule::create([
            'name' => 'Protected Account Created Rule',
            'trigger_type' => 'ACCOUNT_CREATED',
            'template_id' => $template->id,
            'is_active' => true,
            'scope' => 'GLOBAL',
            'recipients_json' => ['client'],
        ]);

        $executor = $this->app->make(AutomationWorkflowExecutor::class);
        $resetLink = 'https://reprodashboard.com/reset-password?token=protected&email=' . urlencode($user->email);

        $executor->executeEventTrigger('ACCOUNT_CREATED', [
            'account_id' => $user->id,
            'client' => $user,
            'password_reset_link' => $resetLink,
        ]);

        $message = Message::query()->where('related_account_id', $user->id)->first();
        $this->assertNotNull($message);
        $this->assertStringNotContainsString('LEGACY HTML SHOULD NOT SHIP', (string) $message->body_html);
        $this->assertSame('ACCOUNT_CREATED', $message->send_source);
        $this->assertSame('ACCOUNT_CREATED', $message->metadata['canonical_email']['email_alias'] ?? null);

        $dispatch = SystemEmailDispatch::query()->where('message_id', $message->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertSame('ACCOUNT_CREATED_V1', $dispatch->email_type);
    }

    public function test_mail_service_no_longer_renders_protected_email_blade_views_directly(): void
    {
        $source = file_get_contents(app_path('Services/MailService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString("view('emails.account_created'", $source);
        $this->assertStringNotContainsString("view('emails.client_email_verification'", $source);
        $this->assertStringNotContainsString("view('emails.password_reset'", $source);
        $this->assertStringNotContainsString("view('emails.shoot_scheduled'", $source);
        $this->assertStringNotContainsString("view('emails.shoot_updated'", $source);
        $this->assertStringNotContainsString("view('emails.invoice_generated'", $source);
        $this->assertStringNotContainsString("view('emails.photographer_changed'", $source);
    }

    public function test_canonical_orchestrator_can_force_resend_after_idempotent_send(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'force-resend@example.com',
            'name' => 'Force Resend',
        ]);

        $payload = $this->app->make(EmailContextBuilder::class)->build([
            'recipient' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'links' => [
                'reset_password' => 'https://reprodashboard.com/reset-password?token=force&email=' . urlencode($user->email),
                'dashboard' => 'https://reprodashboard.com',
            ],
            'meta' => [
                'recipient_type' => 'client',
                'event_version' => 'force-resend-scenario',
            ],
        ]);

        $orchestrator = $this->app->make(SystemEmailOrchestrator::class);
        $transport = [
            'to' => $user->email,
            'related_account_id' => $user->id,
            'send_source' => 'ACCOUNT_CREATED',
            'contact_email' => $user->email,
            'contact_name' => $user->name,
            'contact_type' => 'client',
        ];

        $first = $orchestrator->send('ACCOUNT_CREATED', $payload, $transport);
        $forced = $orchestrator->send('ACCOUNT_CREATED', $payload, $transport, ['force' => true]);

        $this->assertTrue($first['sent']);
        $this->assertTrue($forced['sent']);
        $this->assertFalse($forced['duplicate']);
        $this->assertSame(2, Message::query()->where('related_account_id', $user->id)->count());
        $this->assertSame(2, SystemEmailDispatch::query()->where('email_alias', 'ACCOUNT_CREATED')->count());
    }

    public function test_canonical_dispatch_audit_marks_failures_and_updates_message_delivery_metadata(): void
    {
        $this->createDefaultEmailChannel();
        $this->app->instance(LocalSmtpProvider::class, new class extends LocalSmtpProvider
        {
            public function send(\App\Models\MessageChannel $channel, array $payload): string
            {
                throw new \RuntimeException('Simulated provider failure');
            }
        });

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'provider-failure@example.com',
            'name' => 'Provider Failure',
        ]);

        $service = $this->app->make(MailService::class);
        $resetLink = 'https://reprodashboard.com/reset-password?token=failure&email=' . urlencode($user->email);

        $this->assertFalse($service->sendAccountCreatedEmail($user, $resetLink));

        $message = Message::query()->where('related_account_id', $user->id)->first();
        $dispatch = SystemEmailDispatch::query()->where('email_alias', 'ACCOUNT_CREATED')->first();

        $this->assertNotNull($message);
        $this->assertNotNull($dispatch);
        $this->assertSame('FAILED', $message->status);
        $this->assertSame('FAILED', $message->metadata['delivery']['status'] ?? null);
        $this->assertSame('LOCAL_SMTP', $message->metadata['delivery']['provider'] ?? null);
        $this->assertSame('failed', $dispatch->status);
        $this->assertSame('RuntimeException', $dispatch->error_code);
    }

    public function test_mail_service_and_automation_path_do_not_double_send_same_protected_event(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'shared-idempotency@example.com',
            'name' => 'Shared Idempotency',
        ]);

        AutomationRule::create([
            'name' => 'Protected Account Created Rule',
            'trigger_type' => 'ACCOUNT_CREATED',
            'is_active' => true,
            'scope' => 'GLOBAL',
            'recipients_json' => ['client'],
        ]);

        $executor = $this->app->make(AutomationWorkflowExecutor::class);
        $service = $this->app->make(MailService::class);
        $resetLink = 'https://reprodashboard.com/reset-password?token=shared&email=' . urlencode($user->email);

        $executor->executeEventTrigger('ACCOUNT_CREATED', [
            'account_id' => $user->id,
            'client' => $user,
            'password_reset_link' => $resetLink,
        ]);

        $this->assertTrue($service->sendAccountCreatedEmail($user, $resetLink));
        $this->assertSame(1, Message::query()->where('related_account_id', $user->id)->count());
        $this->assertSame(1, SystemEmailDispatch::query()->where('email_alias', 'ACCOUNT_CREATED')->count());
    }

    public function test_verification_email_dispatch_records_token_metadata(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'verification-metadata@example.com',
            'name' => 'Verification Metadata',
            'email_status' => 'unverified',
        ]);

        $service = $this->app->make(MailService::class);

        $this->assertTrue($service->sendClientEmailVerificationEmail($user, [
            'issued_context' => 'dashboard_resend',
            'issued_by' => $user->id,
        ]));

        $message = Message::query()
            ->where('related_account_id', $user->id)
            ->where('send_source', 'CLIENT_EMAIL_VERIFICATION')
            ->first();

        $dispatch = SystemEmailDispatch::query()
            ->where('email_alias', 'CLIENT_EMAIL_VERIFICATION')
            ->first();

        $this->assertNotNull($message);
        $this->assertNotNull($dispatch);
        $this->assertNotNull($message->metadata['canonical_email']['verification_token_id'] ?? null);
        $this->assertSame('dashboard_resend', $message->metadata['canonical_email']['verification_issued_context'] ?? null);
        $this->assertSame(
            $message->metadata['canonical_email']['verification_token_id'] ?? null,
            $dispatch->metadata['canonical_metadata']['verification_token_id'] ?? null
        );
    }

    public function test_client_email_verified_confirmation_is_dispatched_through_the_canonical_pipeline(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $user = User::factory()->create([
            'role' => 'client',
            'email' => 'verified-confirmation@example.com',
            'name' => 'Verified Confirmation',
            'email_status' => 'verified',
        ]);

        $service = $this->app->make(MailService::class);

        $this->assertTrue($service->sendClientEmailVerifiedEmail($user, [
            'verification_token_id' => 42,
        ]));

        $message = Message::query()
            ->where('related_account_id', $user->id)
            ->where('send_source', 'CLIENT_EMAIL_VERIFIED')
            ->first();

        $dispatch = SystemEmailDispatch::query()
            ->where('email_alias', 'CLIENT_EMAIL_VERIFIED')
            ->first();

        $this->assertNotNull($message);
        $this->assertNotNull($dispatch);
        $this->assertSame('CLIENT_EMAIL_VERIFIED_V1', $message->metadata['canonical_email']['email_type'] ?? null);
        $this->assertSame(42, $message->metadata['canonical_email']['verification_token_id'] ?? null);
        $this->assertSame('CLIENT_EMAIL_VERIFIED_V1', $dispatch->email_type);
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
}
