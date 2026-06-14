<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Jobs\GenerateShootMediaArchiveJob;
use App\Jobs\PublishShootToBrightMlsJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\AutomationRule;
use App\Models\MessageChannel;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootActivityLog;
use App\Models\ShootFile;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\ShootActivityLogger;
use App\Services\SystemEmails\SystemEmailOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Fix-checking tests for QA issue #3.
 *
 * Verifies the implemented fix produces the expected behavior:
 *   - the delivery email fires on the full-order delivered transition
 *     (including no-media deliveries) and bypasses the email-health gate while
 *     retaining the idempotency key;
 *   - the automation executor honors `system_email_already_sent`;
 *   - the full finalize -> deliver flow records the SHOOT_DELIVERED dispatch and
 *     the shoot_finalized_delivered activity entry;
 *   - exactly one client delivery email results even when both the protected
 *     path and the SHOOT_COMPLETED automation event execute.
 *
 * Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7
 */
class DeliveryEmailFixTest extends TestCase
{
    use RefreshDatabase;

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

    private function createDeliverableShoot(User $client, array $overrides = []): Shoot
    {
        $service = Service::factory()->create(['name' => 'Fix Service', 'price' => 250.00]);

        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '742 QA Delivery Test Ave',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'base_quote' => 250.00,
            'total_quote' => 250.00,
        ], $overrides));

        $shoot->services()->attach($service->id, [
            'price' => $service->price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh(['client', 'services']);
    }

    private function addEditedFile(Shoot $shoot, User $uploadedBy): void
    {
        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'final.jpg',
            'stored_filename' => 'final.jpg',
            'path' => "shoots/{$shoot->id}/completed/final.jpg",
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $uploadedBy->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);
    }

    // ----- Unit-style -----

    public function test_finalize_dispatches_ready_email_for_no_media_full_order_delivery(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => null,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'shoot_type' => Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
            'product_status' => Shoot::PRODUCT_STATUS_NO_PRODUCT,
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
            'payment_status' => 'paid',
            'bypass_paywall' => true,
        ]);

        (new FinalizeShootJob($shoot->id, $admin->id, 'completed', null, true))
            ->handle(app(ShootActivityLogger::class));

        Queue::assertPushed(
            SendShootReadyEmailJob::class,
            fn (SendShootReadyEmailJob $job) => $job->shootId === $shoot->id && $job->isFullOrderDelivery === true
        );
    }

    public function test_delivery_email_bypasses_health_gate_and_retains_idempotency_key(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        // Bounced client would be blocked by the email-health gate on the old code.
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'fix-bounced@example.com',
            'email_status' => 'bounced',
        ]);
        $shoot = $this->createDeliverableShoot($client);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $mail->sendShootReadyEmail($client, $shoot);
        // A second send must dedupe via the retained idempotency key.
        $mail->sendShootReadyEmail($client, $shoot);

        $dispatches = SystemEmailDispatch::query()
            ->where('email_alias', 'SHOOT_DELIVERED')
            ->where('recipient_email', $client->email)
            ->get();

        $this->assertCount(1, $dispatches, 'Delivery email must be sent once (gate bypassed, duplicates prevented).');
        $this->assertStringStartsWith('SHOOT_DELIVERED:', (string) $dispatches->first()->idempotency_key);
    }

    public function test_mail_service_passes_gate_false_to_orchestrator_for_delivered_email(): void
    {
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'fix-gate@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createDeliverableShoot($client);

        $capturedOptions = null;

        // Spy directly on the orchestrator so we can assert the exact options
        // MailService forwards for the SHOOT_DELIVERED dispatch.
        $orchestrator = Mockery::mock(SystemEmailOrchestrator::class);
        $orchestrator->shouldReceive('send')
            ->once()
            ->with(
                'SHOOT_DELIVERED',
                Mockery::type('array'),
                Mockery::type('array'),
                Mockery::on(function ($options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                })
            )
            ->andReturn(['sent' => true, 'duplicate' => false, 'dispatch' => null, 'message_id' => 1]);

        $this->app->instance(SystemEmailOrchestrator::class, $orchestrator);

        /** @var MailService $mail */
        $mail = $this->app->make(MailService::class);
        $this->assertTrue($mail->sendShootReadyEmail($client, $shoot));

        $this->assertIsArray($capturedOptions);
        $this->assertArrayHasKey('enforce_email_health_gate', $capturedOptions);
        $this->assertFalse(
            $capturedOptions['enforce_email_health_gate'],
            'SHOOT_DELIVERED must be dispatched with the email-health gate disabled.'
        );
        // The idempotency key must be retained alongside the disabled gate so
        // bypassing the gate cannot produce duplicate client emails.
        $this->assertNotEmpty(
            $capturedOptions['idempotency_key'] ?? null,
            'The idempotency key must be retained when the gate is bypassed.'
        );
        $this->assertStringStartsWith('SHOOT_DELIVERED:', (string) $capturedOptions['idempotency_key']);
    }

    public function test_automation_executor_skips_client_send_when_system_email_already_sent(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'fix-skip@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createDeliverableShoot($client);

        $template = MessageTemplate::create([
            'channel' => 'EMAIL', 'name' => 'SC', 'subject' => 'SC',
            'body_html' => '<p>x</p>', 'body_text' => 'x', 'variables_json' => [],
            'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
        ]);
        AutomationRule::create([
            'name' => 'SC Rule', 'trigger_type' => 'SHOOT_COMPLETED', 'template_id' => $template->id,
            'is_active' => true, 'scope' => 'GLOBAL', 'recipients_json' => ['client'],
        ]);

        /** @var AutomationWorkflowExecutor $executor */
        $executor = $this->app->make(AutomationWorkflowExecutor::class);
        $executor->executeEventTrigger('SHOOT_COMPLETED', [
            'shoot' => $shoot,
            'client' => $client,
            'system_email_already_sent' => true,
        ]);

        $this->assertSame(
            0,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_DELIVERED')
                ->where('recipient_email', $client->email)
                ->count(),
            'When the protected path already sent the client email, the automation must not send another.'
        );
    }

    // ----- Integration -----

    public function test_full_finalize_deliver_flow_records_delivery_email_and_activity(): void
    {
        Mail::fake();
        // Selectively fake only the media-dependent side-effect jobs (Bright MLS
        // publish + the edited-media archive generated by the DELIVERED status
        // transition). These need real downloadable files and are out of scope
        // for the delivery-email assertion; the finalize job and the delivery
        // email path still run synchronously on the real (sync) queue.
        Queue::fake([
            GenerateShootMediaArchiveJob::class,
            PublishShootToBrightMlsJob::class,
        ]);
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'fix-fullflow@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createDeliverableShoot($client);
        $this->addEditedFile($shoot, $admin);

        Sanctum::actingAs($admin);

        $this->postJson('/api/shoots/' . $shoot->id . '/finalize', ['final_status' => 'completed'])
            ->assertAccepted();

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_DELIVERED')
                ->where('recipient_email', $client->email)
                ->count(),
            'The full finalize -> deliver flow must record one SHOOT_DELIVERED dispatch for the client.'
        );

        $this->assertSame(
            1,
            ShootActivityLog::query()
                ->where('shoot_id', $shoot->id)
                ->where('action', 'shoot_finalized_delivered')
                ->count()
        );
    }

    public function test_no_media_finalize_dispatches_delivery_email_end_to_end(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'fix-nomedia@example.com',
            'email_status' => 'verified',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => null,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'shoot_type' => Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
            'product_status' => Shoot::PRODUCT_STATUS_NO_PRODUCT,
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
            'payment_status' => 'paid',
            'bypass_paywall' => true,
        ]);

        (new FinalizeShootJob($shoot->id, $admin->id, 'completed', null, true))
            ->handle(app(ShootActivityLogger::class));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_DELIVERED')
                ->where('recipient_email', $client->email)
                ->count(),
            'No-media (fast-forward) full-order delivery must still dispatch the delivery email.'
        );
    }

    public function test_exactly_one_delivery_email_when_protected_path_and_automation_both_fire(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'fix-dedupe@example.com',
            'email_status' => 'verified',
        ]);
        $shoot = $this->createDeliverableShoot($client);

        $template = MessageTemplate::create([
            'channel' => 'EMAIL', 'name' => 'SC2', 'subject' => 'SC2',
            'body_html' => '<p>x</p>', 'body_text' => 'x', 'variables_json' => [],
            'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
        ]);
        AutomationRule::create([
            'name' => 'SC2 Rule', 'trigger_type' => 'SHOOT_COMPLETED', 'template_id' => $template->id,
            'is_active' => true, 'scope' => 'GLOBAL', 'recipients_json' => ['client'],
        ]);

        // Protected send + SHOOT_COMPLETED automation event in one job.
        (new SendShootReadyEmailJob($shoot->id, null, true, true))
            ->handle($this->app->make(MailService::class), $this->app->make(AutomationService::class));

        $this->assertSame(
            1,
            SystemEmailDispatch::query()
                ->where('email_alias', 'SHOOT_DELIVERED')
                ->where('related_shoot_id', $shoot->id)
                ->where('recipient_email', $client->email)
                ->count(),
            'Exactly one client delivery email must be recorded even when both paths execute.'
        );
    }
}
