<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\DispatchScheduledMessages;
use App\Jobs\FinalizeShootJob;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\IngestIguideAssetsJob;
use App\Jobs\PublishShootToBrightMlsJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Jobs\SyncCubiCasaShootJob;
use App\Jobs\SyncShootIguideJob;
use App\Jobs\SyncShootToGoogleCalendarJob;
use App\Jobs\RemoveShootFromGoogleCalendarJob;
use App\Models\Message;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\BrightMlsService;
use App\Services\CubiCasaService;
use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\IguideService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\AutomationWorkflowExecutor;
use App\Services\Messaging\MessagingService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\FinalizeProgressTracker;
use App\Services\Shoots\ShootNotificationDispatchService;
use App\Services\SystemEmails\SystemEmailOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class InternalTestShootIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'scheduled_at' => '2026-09-23 14:00:00',
            'timezone' => 'America/New_York',
            'service_id' => null,
            'base_quote' => 0,
            'tax_amount' => 0,
            'total_quote' => 0,
            'payment_status' => 'unpaid',
        ], $attributes));
    }

    public function test_internal_test_finalizes_local_media_without_external_delivery_jobs(): void
    {
        $shoot = $this->shoot();
        $actor = User::factory()->create(['role' => 'admin']);
        $file = ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'edited.jpg', 'stored_filename' => 'edited.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/edited.jpg', 'file_type' => 'image/jpeg',
            'file_size' => 10, 'media_type' => 'edited', 'uploaded_by' => $actor->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);
        $progress = app(FinalizeProgressTracker::class);
        $progress->start($shoot->id);
        (new FinalizeShootJob($shoot->id, $actor->id))->handle(app(ShootActivityLogger::class), $progress);

        $this->assertSame(Shoot::STATUS_DELIVERED, $shoot->fresh()->workflow_status);
        $this->assertSame(ShootFile::STAGE_VERIFIED, $file->fresh()->workflow_stage);
        Queue::assertNotPushed(SendShootReadyEmailJob::class);
        Queue::assertNotPushed(PublishShootToBrightMlsJob::class);
        $this->assertDatabaseMissing('client_delivery_notifications', ['shoot_id' => $shoot->id]);
        $this->assertDatabaseMissing('payment_reminders', ['shoot_id' => $shoot->id]);
        Http::assertNothingSent();
    }

    public function test_regular_delivery_still_queues_email_and_mls_and_notifies_client(): void
    {
        $shoot = $this->shoot(['shoot_type' => Shoot::SHOOT_TYPE_STANDARD]);
        $actor = User::factory()->create(['role' => 'admin']);
        ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'edited.jpg', 'stored_filename' => 'edited.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/edited.jpg', 'file_type' => 'image/jpeg',
            'file_size' => 10, 'media_type' => 'edited', 'uploaded_by' => $actor->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);
        (new FinalizeShootJob($shoot->id, $actor->id))->handle(app(ShootActivityLogger::class));
        $this->assertSame(Shoot::STATUS_DELIVERED, $shoot->fresh()->workflow_status);
        Queue::assertPushed(SendShootReadyEmailJob::class);
        Queue::assertPushed(PublishShootToBrightMlsJob::class);
        $this->assertDatabaseHas('client_delivery_notifications', ['shoot_id' => $shoot->id]);
    }

    public function test_stale_delivery_and_provider_jobs_recheck_internal_type_before_side_effects(): void
    {
        $shoot = $this->shoot();
        $mail = Mockery::mock(MailService::class);
        $automation = Mockery::mock(AutomationService::class);
        $bright = Mockery::mock(BrightMlsService::class);
        $cubi = Mockery::mock(CubiCasaService::class);
        $iguide = Mockery::mock(IguideService::class);
        (new SendShootReadyEmailJob($shoot->id))->handle($mail, $automation);
        (new PublishShootToBrightMlsJob($shoot->id))->handle($bright, app(ShootActivityLogger::class));
        (new CreateCubiCasaOrderJob($shoot->id))->handle($cubi);
        (new SyncCubiCasaShootJob($shoot->id, 'old-job'))->handle($cubi);
        (new SyncShootIguideJob($shoot->id))->handle($iguide);
        (new IngestCubiCasaAssetsJob($shoot->id, [['url' => 'https://provider.test/asset']]))->handle(app(ShootActivityLogger::class));
        (new IngestIguideAssetsJob($shoot->id, [['url' => 'https://provider.test/asset']]))->handle(app(ShootActivityLogger::class));
        $this->assertNull($shoot->fresh()->shoot_ready_notified_at);
        $this->assertDatabaseMissing('payment_reminders', ['shoot_id' => $shoot->id]);
        Http::assertNothingSent();
    }

    public function test_manual_mls_publish_rejects_internal_shoot(): void
    {
        $shoot = $this->shoot();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/integrations/shoots/'.$shoot->id.'/bright-mls/publish')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_paid_editing_provider_jobs_cancel_internal_shoot_work_without_network_calls(): void
    {
        $shoot = $this->shoot();
        foreach ([\App\Jobs\ProcessFalEditingJob::class, \App\Jobs\ProcessAutoenhanceEditingJob::class] as $jobClass) {
            $editing = \App\Models\AiEditingJob::create([
                'shoot_id' => $shoot->id, 'user_id' => $shoot->client_id,
                'provider' => 'test', 'status' => 'pending', 'editing_type' => 'enhance',
                'original_image_url' => 'https://example.test/qa.jpg',
            ]);
            app()->call([new $jobClass($editing), 'handle']);
            $this->assertSame('cancelled', $editing->fresh()->status);
            $this->assertNull($editing->fresh()->provider_job_id);
        }
        Http::assertNothingSent();
    }

    public function test_direct_provider_services_skip_internal_shoot_even_with_existing_provider_ids(): void
    {
        $shoot = $this->shoot(['cubicasa_order_id' => 'real-provider-id', 'iguide_property_id' => 'real-property-id']);
        $this->assertNull(app(CubiCasaService::class)->createOrder($shoot));
        $this->assertNull(app(CubiCasaService::class)->syncShoot($shoot));
        $this->assertNull(app(IguideService::class)->syncShoot($shoot));
        $this->assertNull(app(BrightMlsService::class)->autoPublishForShoot($shoot));
        Http::assertNothingSent();
    }

    public function test_create_update_notifications_and_direct_mail_are_suppressed(): void
    {
        $shoot = $this->shoot();
        $dispatcher = app(ShootNotificationDispatchService::class);
        $dispatcher->processCreatedShoot($shoot->id, false, true);
        $dispatcher->processUpdatedShoot($shoot->id, 'Photographer changed', '', true, true, null, 'scheduled', 'scheduled', true, true);
        $dispatcher->processExternalShootRequested($shoot->id);
        $mail = app(MailService::class);
        $this->assertFalse($mail->sendShootScheduledEmail($shoot->client, $shoot, 'https://example.test'));
        $this->assertFalse($mail->sendShootReadyEmail($shoot->client, $shoot));
        $this->assertFalse($mail->sendShootRemovedEmail($shoot->client, $shoot));
        $this->assertFalse($mail->sendShootCancelledEmail($shoot->client, $shoot));
        $this->assertSame([], $mail->sendAssignedPhotographerShootScheduledEmailsWithRecipients($shoot));
        $this->assertDatabaseMissing('messages', ['related_shoot_id' => $shoot->id]);
        $this->assertDatabaseMissing('system_email_dispatches', ['related_shoot_id' => $shoot->id]);
        Http::assertNothingSent();
    }

    public function test_calendar_dispatch_and_handler_skip_internal_shoot_but_regular_dispatch_remains(): void
    {
        $shoot = $this->shoot();
        $dispatcher = app(GoogleCalendarSyncDispatcher::class);
        $dispatcher->dispatchShootSync($shoot->id);
        $dispatcher->dispatchShootRemoval($shoot->id);
        app(GoogleCalendarShootSyncService::class)->syncShoot($shoot->id);
        app(GoogleCalendarShootSyncService::class)->removeShoot($shoot->id);
        Queue::assertNotPushed(SyncShootToGoogleCalendarJob::class);
        Queue::assertNotPushed(RemoveShootFromGoogleCalendarJob::class);
        $standard = $this->shoot(['shoot_type' => Shoot::SHOOT_TYPE_STANDARD]);
        $dispatcher->dispatchShootSync($standard->id);
        Queue::assertPushed(SyncShootToGoogleCalendarJob::class);
        Http::assertNothingSent();
    }

    public function test_internal_delete_does_not_send_mail_or_dispatch_calendar_removal(): void
    {
        $shoot = $this->shoot();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/shoots/'.$shoot->id)->assertOk();
        $this->assertDatabaseMissing('shoots', ['id' => $shoot->id]);
        Queue::assertNotPushed(RemoveShootFromGoogleCalendarJob::class);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('system_email_dispatches', 0);
        Http::assertNothingSent();
    }

    public function test_internal_delete_cancels_legacy_outbox_work_before_foreign_keys_are_nulled(): void
    {
        $shoot = $this->shoot();
        $message = Message::create([
            'related_shoot_id' => $shoot->id, 'channel' => 'EMAIL', 'direction' => 'OUTBOUND',
            'provider' => 'TEST', 'to_address' => 'recipient@example.test', 'status' => 'SCHEDULED',
        ]);
        $dispatch = SystemEmailDispatch::create([
            'email_type' => 'shoot_ready', 'email_alias' => 'SHOOT_READY', 'category' => 'transactional',
            'idempotency_key' => 'legacy-queued-ready', 'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'recipient_email' => 'recipient@example.test', 'template_view' => 'unused', 'status' => 'pending',
            'related_shoot_id' => $shoot->id,
            'payload_snapshot' => [], 'transport_snapshot' => ['related_shoot_id' => $shoot->id],
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/shoots/'.$shoot->id)->assertOk();
        $this->assertSame('CANCELLED', $message->fresh()->status);
        $this->assertSame('suppressed', $dispatch->fresh()->status);
        app(SystemEmailOrchestrator::class)->processQueued($dispatch);
        $this->assertNull($dispatch->fresh()->sent_at);
        Http::assertNothingSent();
    }

    public function test_internal_test_classification_cannot_be_removed_by_an_edit(): void
    {
        $shoot = $this->shoot();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/shoots/'.$shoot->id, ['shoot_type' => Shoot::SHOOT_TYPE_STANDARD])->assertUnprocessable();
        $this->assertSame(Shoot::SHOOT_TYPE_INTERNAL_TEST, $shoot->fresh()->shoot_type);
    }

    public function test_reminder_schedule_and_stale_due_reminder_are_cancelled(): void
    {
        $shoot = $this->shoot(['shoot_ready_notified_at' => now()->subDays(2)]);
        $automation = app(AutomationService::class);
        $this->assertSame([], $automation->schedulePaymentReminders($shoot));
        $this->assertNull($automation->sendPaymentReminder($shoot));
        $reminder = PaymentReminder::create([
            'shoot_id' => $shoot->id, 'scheduled_date' => now()->toDateString(),
            'scheduled_at' => now()->subMinute(), 'status' => PaymentReminder::STATUS_PENDING,
        ]);
        $workflow = Mockery::mock(AutomationWorkflowExecutor::class);
        $workflow->shouldReceive('resumeDueSteps')->once();
        (new DispatchScheduledMessages())->handle(app(MessagingService::class), $workflow, $automation);
        $this->assertSame(PaymentReminder::STATUS_CANCELLED, $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->sent_at);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_previously_queued_messages_are_cancelled_before_provider_resolution(): void
    {
        $shoot = $this->shoot();
        foreach (['EMAIL', 'SMS'] as $channel) {
            $message = Message::create([
                'related_shoot_id' => $shoot->id, 'channel' => $channel, 'direction' => 'OUTBOUND',
                'provider' => 'TEST', 'to_address' => 'recipient@example.test', 'status' => 'SCHEDULED',
            ]);
            app(MessagingService::class)->dispatchScheduledMessage($message);
            $this->assertSame('CANCELLED', $message->fresh()->status);
            $this->assertNull($message->fresh()->sent_at);
        }
        Http::assertNothingSent();
    }

    public function test_queued_protected_email_rechecks_type_and_remains_suppressed_after_shoot_deletion(): void
    {
        $shoot = $this->shoot();
        $dispatch = SystemEmailDispatch::create([
            'email_type' => 'shoot_ready', 'email_alias' => 'SHOOT_READY', 'category' => 'transactional',
            'idempotency_key' => 'qa-queued-ready', 'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'recipient_email' => 'recipient@example.test', 'template_view' => 'unused', 'status' => 'pending',
            'related_shoot_id' => $shoot->id,
            'payload_snapshot' => ['shoot' => ['id' => $shoot->id, 'shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST]],
            'transport_snapshot' => ['related_shoot_id' => $shoot->id],
        ]);
        $shoot->delete();
        app(SystemEmailOrchestrator::class)->processQueued($dispatch);
        $this->assertSame('suppressed', $dispatch->fresh()->status);
        $this->assertNull($dispatch->fresh()->sent_at);
        Http::assertNothingSent();
    }
}
