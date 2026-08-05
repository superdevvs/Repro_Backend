<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
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
}
