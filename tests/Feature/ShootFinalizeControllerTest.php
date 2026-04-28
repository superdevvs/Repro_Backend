<?php

namespace Tests\Feature;

use App\Jobs\FinalizeShootJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
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
}
