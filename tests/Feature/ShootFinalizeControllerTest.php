<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootFinalizeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createLinkOnlyShoot(array $overrides = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => User::factory()->create(['role' => 'client'])->id,
            'service_id' => null,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'product_status' => Shoot::PRODUCT_STATUS_HAS_PRODUCT,
            'base_quote' => 200,
            'tax_amount' => 0,
            'total_quote' => 200,
            'tour_links' => ['video_link' => 'https://video.example.test/tour/123'],
        ], $overrides));
    }

    private function attachService(Shoot $shoot, string $name, float $price, int $quantity = 1): Service
    {
        $service = Service::factory()->create([
            'name' => $name,
            'price' => $price,
        ]);

        $shoot->services()->attach($service->id, [
            'price' => $price,
            'quantity' => $quantity,
            'workflow_status' => 'scheduled',
            'delivery_status' => 'not_started',
            'is_deliverable' => true,
        ]);

        return $service;
    }

    public function test_finalize_queues_background_job_and_returns_accepted(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $editor = User::factory()->create(['role' => 'editor']);
        $service = Service::factory()->create();

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'editor_id' => $editor->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
        ]);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'final.jpg',
            'stored_filename' => 'final.jpg',
            'path' => 'shoots/' . $shoot->id . '/completed/final.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/finalize', [
            'final_status' => 'completed',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('message', 'Finalize started in background')
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(FinalizeShootJob::class, function (FinalizeShootJob $job) use ($shoot, $admin) {
            return $job->shootId === $shoot->id
                && $job->userId === $admin->id
                && $job->finalStatus === 'completed';
        });
    }

    public function test_internal_no_charge_shoot_can_finalize_without_media_when_explicitly_confirmed(): void
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

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/finalize', [
            'allow_no_media_delivery' => true,
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(FinalizeShootJob::class, function (FinalizeShootJob $job) use ($shoot) {
            return $job->shootId === $shoot->id
                && $job->allowNoMediaDelivery === true;
        });
    }

    public function test_standard_shoot_without_media_still_cannot_finalize(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create();

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'product_status' => Shoot::PRODUCT_STATUS_HAS_PRODUCT,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/finalize', [
            'allow_no_media_delivery' => true,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('message', 'No edited files to finalize');

        Queue::assertNotPushed(FinalizeShootJob::class);
    }

    public function test_admin_and_superadmin_can_queue_link_only_delivery_without_services(): void
    {
        Queue::fake();

        foreach (['admin', 'superadmin', 'super_admin'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $shoot = $this->createLinkOnlyShoot();

            Sanctum::actingAs($actor);

            $this->postJson('/api/shoots/'.$shoot->id.'/finalize', [
                'allow_no_media_delivery' => true,
            ])->assertAccepted();

            Queue::assertPushed(FinalizeShootJob::class, fn (FinalizeShootJob $job) =>
                $job->shootId === $shoot->id
                && $job->userId === $actor->id
                && $job->allowNoMediaDelivery
            );
        }
    }

    public function test_each_managed_http_video_link_key_qualifies(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        foreach (['video_link', 'video_branded', 'video_mls', 'video_generic'] as $key) {
            $shoot = $this->createLinkOnlyShoot([
                'tour_links' => [$key => 'https://video.example.test/'.$key],
            ]);

            $this->postJson('/api/shoots/'.$shoot->id.'/finalize', [
                'allow_no_media_delivery' => true,
            ])->assertAccepted();
        }
    }

    public function test_link_only_delivery_accepts_test_or_free_service_alongside_paid_services(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $testShoot = $this->createLinkOnlyShoot();
        $this->attachService($testShoot, 'Premium Test Video Tour', 150);
        $this->attachService($testShoot, 'Regular Photography', 400);

        $this->postJson('/api/shoots/'.$testShoot->id.'/finalize', [
            'allow_no_media_delivery' => true,
        ])->assertAccepted();

        $freeShoot = $this->createLinkOnlyShoot();
        $this->attachService($freeShoot, 'Complimentary Add-on', 0.01);
        $this->attachService($freeShoot, 'Regular Paid Video', 350);

        $this->postJson('/api/shoots/'.$freeShoot->id.'/finalize', [
            'allow_no_media_delivery' => true,
        ])->assertAccepted();

        $bookedFreeShoot = $this->createLinkOnlyShoot();
        $cataloguePaidService = Service::factory()->create([
            'name' => 'Catalogue-priced Add-on',
            'price' => 500,
        ]);
        $bookedFreeShoot->services()->attach($cataloguePaidService->id, [
            'price' => 0.01,
            'quantity' => 1,
            'workflow_status' => 'scheduled',
            'delivery_status' => 'not_started',
            'is_deliverable' => true,
        ]);

        $this->postJson('/api/shoots/'.$bookedFreeShoot->id.'/finalize', [
            'allow_no_media_delivery' => true,
        ])->assertAccepted();
    }

    public function test_link_only_delivery_requires_explicit_whole_shoot_opt_in(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $shoot = $this->createLinkOnlyShoot();
        $this->postJson('/api/shoots/'.$shoot->id.'/finalize')
            ->assertBadRequest();

        $serviceScopedShoot = $this->createLinkOnlyShoot();
        $this->attachService($serviceScopedShoot, 'Test Video Tour', 100);
        $shootServiceId = $serviceScopedShoot->serviceItems()->value('id');

        $this->postJson('/api/shoots/'.$serviceScopedShoot->id.'/finalize', [
            'shoot_service_id' => $shootServiceId,
            'allow_no_media_delivery' => true,
        ])->assertBadRequest();

        Queue::assertNothingPushed();
    }

    public function test_link_only_delivery_is_available_from_each_supported_status(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        foreach (['booked', Shoot::STATUS_SCHEDULED, Shoot::STATUS_ON_HOLD, Shoot::STATUS_UPLOADED, Shoot::STATUS_EDITING, Shoot::STATUS_READY] as $status) {
            $shoot = $this->createLinkOnlyShoot([
                'status' => $status,
                'workflow_status' => $status,
            ]);

            $this->postJson('/api/shoots/'.$shoot->id.'/finalize', [
                'allow_no_media_delivery' => true,
            ])->assertAccepted();
        }
    }

    public function test_link_only_delivery_rejects_editing_manager_invalid_links_and_paid_only_services(): void
    {
        Queue::fake();

        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        $editingManagerShoot = $this->createLinkOnlyShoot();
        Sanctum::actingAs($editingManager);
        $this->postJson('/api/shoots/'.$editingManagerShoot->id.'/finalize', [
            'allow_no_media_delivery' => true,
        ])->assertBadRequest();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        foreach ([[], ['video_link' => 'not-a-url'], ['video_link' => 'ftp://video.example.test/tour']] as $tourLinks) {
            $shoot = $this->createLinkOnlyShoot(['tour_links' => $tourLinks]);
            $this->postJson('/api/shoots/'.$shoot->id.'/finalize', [
                'allow_no_media_delivery' => true,
            ])->assertBadRequest();
        }

        $paidOnlyShoot = $this->createLinkOnlyShoot();
        $this->attachService($paidOnlyShoot, 'Regular Video Tour', 125);
        $this->postJson('/api/shoots/'.$paidOnlyShoot->id.'/finalize', [
            'allow_no_media_delivery' => true,
        ])->assertBadRequest();

        $legacyPaidService = Service::factory()->create([
            'name' => 'Legacy Regular Photography',
            'price' => 300,
        ]);
        $legacyPaidOnlyShoot = $this->createLinkOnlyShoot([
            'service_id' => $legacyPaidService->id,
        ]);
        $this->postJson('/api/shoots/'.$legacyPaidOnlyShoot->id.'/finalize', [
            'allow_no_media_delivery' => true,
        ])->assertBadRequest();

        Queue::assertNothingPushed();
    }

    public function test_link_only_delivery_rejects_raw_uploads_and_non_delivery_statuses(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $rawShoot = $this->createLinkOnlyShoot();
        ShootFile::create([
            'shoot_id' => $rawShoot->id,
            'filename' => 'raw.jpg',
            'stored_filename' => 'raw.jpg',
            'path' => 'shoots/'.$rawShoot->id.'/raw/raw.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'raw',
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);

        $this->postJson('/api/shoots/'.$rawShoot->id.'/finalize', [
            'allow_no_media_delivery' => true,
        ])->assertBadRequest();

        foreach ([Shoot::STATUS_REQUESTED, Shoot::STATUS_REVIEW, Shoot::STATUS_DELIVERED, Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED] as $status) {
            $shoot = $this->createLinkOnlyShoot([
                'status' => $status,
                'workflow_status' => $status,
            ]);

            $this->postJson('/api/shoots/'.$shoot->id.'/finalize', [
                'allow_no_media_delivery' => true,
            ])->assertBadRequest();
        }

        Queue::assertNothingPushed();
    }

    public function test_shoot_payload_exposes_role_specific_no_media_capability(): void
    {
        $shoot = $this->createLinkOnlyShoot();
        $admin = User::factory()->create(['role' => 'admin']);
        $editingManager = User::factory()->create(['role' => 'editing_manager']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/shoots/'.$shoot->id)
            ->assertOk()
            ->assertJsonPath('data.canFinalizeNoMedia', true)
            ->assertJsonPath('data.can_finalize_no_media', true);

        Sanctum::actingAs($editingManager);
        $this->getJson('/api/shoots/'.$shoot->id)
            ->assertOk()
            ->assertJsonPath('data.canFinalizeNoMedia', false)
            ->assertJsonPath('data.can_finalize_no_media', false);
    }

    public function test_editing_manager_can_still_finalize_normal_edited_media(): void
    {
        Queue::fake();

        $editingManager = User::factory()->create(['role' => 'editing_manager']);
        $shoot = $this->createLinkOnlyShoot([
            'tour_links' => [],
        ]);
        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'final.jpg',
            'stored_filename' => 'final.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/final.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'edited',
            'uploaded_by' => $editingManager->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
        ]);

        Sanctum::actingAs($editingManager);
        $this->postJson('/api/shoots/'.$shoot->id.'/finalize', [
            'final_status' => 'completed',
        ])->assertAccepted();

        Queue::assertPushed(FinalizeShootJob::class, fn (FinalizeShootJob $job) =>
            $job->shootId === $shoot->id && ! $job->allowNoMediaDelivery
        );
    }

    public function test_finalize_job_revalidates_no_media_eligibility_before_committing(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'service_id' => null,
            'status' => Shoot::STATUS_READY,
            'workflow_status' => Shoot::STATUS_READY,
            'shoot_type' => Shoot::SHOOT_TYPE_SAMPLE_UPLOAD,
            'product_status' => Shoot::PRODUCT_STATUS_NO_PRODUCT,
            'total_quote' => 0,
        ]);

        $job = new FinalizeShootJob($shoot->id, $admin->id, 'completed', null, true);

        // Simulate the shoot becoming ineligible after the request was queued.
        $shoot->update([
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD,
            'product_status' => Shoot::PRODUCT_STATUS_HAS_PRODUCT,
            'total_quote' => 100,
        ]);

        $job->handle(app(ShootActivityLogger::class));

        $this->assertSame(Shoot::STATUS_READY, $shoot->fresh()->workflow_status);
        $this->assertDatabaseHas('workflow_logs', [
            'shoot_id' => $shoot->id,
            'action' => 'finalize_failed',
            'details' => 'Finalize aborted: no edited files found',
        ]);
    }

    public function test_link_only_job_delivers_all_service_rows_and_queues_client_notification(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = $this->createLinkOnlyShoot();
        $this->attachService($shoot, 'Test Video Tour', 100);
        $this->attachService($shoot, 'Regular Paid Photography', 450);
        $videoUrl = $shoot->tour_links['video_link'];

        $job = new FinalizeShootJob($shoot->id, $admin->id, 'completed', null, true);
        $job->handle(app(ShootActivityLogger::class));

        $deliveredShoot = $shoot->fresh();
        $this->assertSame(Shoot::STATUS_DELIVERED, $deliveredShoot->workflow_status);
        $this->assertSame($videoUrl, $deliveredShoot->tour_links['video_link']);
        $this->assertSame(
            0,
            $deliveredShoot->serviceItems()
                ->where(function ($query) {
                    $query->where('workflow_status', '!=', 'delivered')
                        ->orWhere('delivery_status', '!=', 'delivered');
                })
                ->count()
        );
        $this->assertDatabaseHas('client_delivery_notifications', [
            'shoot_id' => $shoot->id,
            'user_id' => $shoot->client_id,
        ]);
        Queue::assertPushed(SendShootReadyEmailJob::class, fn (SendShootReadyEmailJob $queuedJob) =>
            $queuedJob->shootId === $shoot->id
        );
    }

    public function test_finalize_job_revalidates_each_link_only_input_before_committing(): void
    {
        Queue::fake();

        $mutations = [
            'video link removed' => function (Shoot $shoot): void {
                $shoot->tour_links = [];
                $shoot->save();
            },
            'qualifying service changed' => function (Shoot $shoot): void {
                $service = $this->attachService($shoot, 'Test Video Tour', 100);
                $service->update(['name' => 'Regular Video Tour']);
            },
            'actor role changed' => function (Shoot $shoot, User $actor): void {
                $actor->update(['role' => 'editing_manager']);
            },
            'shoot status changed' => function (Shoot $shoot): void {
                $shoot->update([
                    'status' => Shoot::STATUS_REVIEW,
                    'workflow_status' => Shoot::STATUS_REVIEW,
                ]);
            },
        ];

        foreach ($mutations as $label => $mutation) {
            $admin = User::factory()->create(['role' => 'admin']);
            $shoot = $this->createLinkOnlyShoot();
            $job = new FinalizeShootJob($shoot->id, $admin->id, 'completed', null, true);

            $mutation($shoot, $admin);
            $expectedStatus = $shoot->fresh()->workflow_status;

            $job->handle(app(ShootActivityLogger::class));

            $this->assertSame($expectedStatus, $shoot->fresh()->workflow_status, $label);
            $this->assertDatabaseHas('workflow_logs', [
                'shoot_id' => $shoot->id,
                'action' => 'finalize_failed',
            ]);
        }
    }
}
