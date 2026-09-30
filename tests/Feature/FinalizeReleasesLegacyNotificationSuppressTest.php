<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\FinalizeProgressTracker;
use App\Services\Shoots\ShootNotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Legacy-imported bookings are muted with notifications_suppressed only to quiet
 * bulk import-time side effects. Post-import meaningful actions must release the
 * mute so finalize, delivery, updates, payments, and manual messages can notify.
 * Create-time side effects and cron pre-checks keep the mute until release.
 */
class FinalizeReleasesLegacyNotificationSuppressTest extends TestCase
{
    use RefreshDatabase;

    private function createLegacyMutedDeliverableShoot(User $client, array $extra = []): Shoot
    {
        $service = Service::factory()->create(['name' => 'HDR Photos & Video', 'price' => 299.00]);

        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '6003 Calla Place',
            'city' => 'Frederick',
            'state' => 'MD',
            'zip' => '21703',
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'base_quote' => 299.00,
            'total_quote' => 299.00,
            'external_booking_payload' => [
                'legacy_migration' => [
                    'source' => 'pro.reprophotos.com',
                    'source_id' => '466125',
                    'batch' => 'legacy-scheduled-20260928',
                    'notifications_suppressed' => true,
                ],
            ],
        ], $extra));

        $shoot->services()->attach($service->id, [
            'price' => $service->price,
            'quantity' => 1,
            'is_deliverable' => true,
            'workflow_status' => 'ready',
            'delivery_status' => 'not_started',
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => '6003-calla-edited.jpg',
            'stored_filename' => '6003-calla-edited.jpg',
            'path' => "shoots/{$shoot->id}/completed/6003-calla-edited.jpg",
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $client->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        return $shoot->fresh();
    }

    public function test_full_order_finalize_releases_legacy_notification_mute(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'superadmin']);
        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $shoot = $this->createLegacyMutedDeliverableShoot($client);

        $invoice = Invoice::factory()->create([
            'shoot_id' => $shoot->id,
            'client_id' => $client->id,
            'payment_details' => [
                'legacy_migration' => [
                    'notifications_suppressed' => true,
                ],
            ],
        ]);

        $this->assertTrue($shoot->suppressesExternalNotifications());
        $this->assertTrue($invoice->suppressesExternalNotifications());
        $this->assertSame(
            'Legacy import: external notifications suppressed until released',
            $shoot->externalNotificationSuppressionReason()
        );

        (new FinalizeShootJob($shoot->id, $admin->id, 'admin_verified'))
            ->handle(app(ShootActivityLogger::class));

        $shoot->refresh();
        $invoice->refresh();

        $this->assertSame(Shoot::STATUS_DELIVERED, $shoot->workflow_status);
        $this->assertFalse($shoot->suppressesExternalNotifications());
        $this->assertFalse(data_get($shoot->external_booking_payload, 'legacy_migration.notifications_suppressed'));
        $this->assertSame(
            'finalize_delivered',
            data_get($shoot->external_booking_payload, 'legacy_migration.notifications_released_reason')
        );
        $this->assertNotEmpty(data_get($shoot->external_booking_payload, 'legacy_migration.notifications_released_at'));
        $this->assertFalse($invoice->suppressesExternalNotifications());
        $this->assertFalse(data_get($invoice->payment_details, 'legacy_migration.notifications_suppressed'));

        Queue::assertPushed(
            SendShootReadyEmailJob::class,
            fn (SendShootReadyEmailJob $job) => $job->shootId === $shoot->id && $job->isFullOrderDelivery === true
        );
    }

    public function test_delivery_email_job_releases_legacy_mute_instead_of_skipping(): void
    {
        Mail::fake();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Test delivery',
            'from_email' => 'delivery@example.test',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);

        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $shoot = $this->createLegacyMutedDeliverableShoot($client);

        $progressTracker = app(FinalizeProgressTracker::class);
        $progressTracker->start($shoot->id);

        (new SendShootReadyEmailJob($shoot->id, null, true, true))
            ->handle(app(\App\Services\MailService::class), app(\App\Services\Messaging\AutomationService::class));

        $shoot->refresh();
        $this->assertFalse($shoot->suppressesExternalNotifications());
        $this->assertSame(1, Message::where('related_shoot_id', $shoot->id)
            ->where('send_source', 'SHOOT_DELIVERED')->where('status', 'SENT')->count());
        $this->assertSame(
            'delivery_email_full_order',
            data_get($shoot->external_booking_payload, 'legacy_migration.notifications_released_reason')
        );

        $progress = $progressTracker->get($shoot->id);
        $emailStage = collect($progress['stages'] ?? [])->firstWhere('key', FinalizeProgressTracker::STAGE_DELIVERY_EMAIL);
        $this->assertNotSame(
            'Legacy import: external notifications suppressed until released',
            $emailStage['message'] ?? null
        );
        $this->assertStringNotContainsString('Internal test', (string) ($emailStage['message'] ?? ''));
    }

    public function test_staff_update_side_effects_release_legacy_mute(): void
    {
        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $shoot = $this->createLegacyMutedDeliverableShoot($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $this->assertTrue($shoot->suppressesExternalNotifications());

        app(ShootNotificationDispatchService::class)->processUpdatedShoot(
            $shoot->id,
            "Schedule: changed\n",
            '<p>Schedule: changed</p>',
            true,
            false,
            null,
            Shoot::STATUS_SCHEDULED,
            Shoot::STATUS_SCHEDULED,
            false,
            false
        );

        $shoot->refresh();
        $this->assertFalse($shoot->suppressesExternalNotifications());
        $this->assertSame(
            'shoot_updated',
            data_get($shoot->external_booking_payload, 'legacy_migration.notifications_released_reason')
        );
    }

    public function test_create_side_effects_keep_legacy_mute_without_releasing(): void
    {
        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $shoot = $this->createLegacyMutedDeliverableShoot($client, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        app(ShootNotificationDispatchService::class)->processCreatedShoot($shoot->id, false, true);

        $shoot->refresh();
        $this->assertTrue($shoot->suppressesExternalNotifications());
        $this->assertTrue(data_get($shoot->external_booking_payload, 'legacy_migration.notifications_suppressed'));
        $this->assertNull(data_get($shoot->external_booking_payload, 'legacy_migration.notifications_released_reason'));
    }
}
