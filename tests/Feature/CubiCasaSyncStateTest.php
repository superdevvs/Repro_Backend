<?php

namespace Tests\Feature;

use App\Jobs\SyncCubiCasaShootJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CubiCasaSyncStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_async_cubicasa_sync_is_idempotent_while_queued(): void
    {
        Queue::fake();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => 'order-123',
        ]);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/sync", [
            'async' => true,
        ])
            ->assertStatus(202)
            ->assertJsonPath('mode', 'queued')
            ->assertJsonPath('sync.sync_status', CubiCasaService::SYNC_STATUS_QUEUED);

        $jobId = $shoot->fresh()->cubicasa_sync_job_id;
        $this->assertNotEmpty($jobId);
        $this->assertNotNull($shoot->fresh()->cubicasa_sync_started_at);

        Queue::assertPushed(SyncCubiCasaShootJob::class, 1);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/sync", [
            'async' => true,
        ])
            ->assertStatus(202)
            ->assertJsonPath('mode', 'sync-in-progress')
            ->assertJsonPath('sync.sync_job_id', $jobId);

        Queue::assertPushed(SyncCubiCasaShootJob::class, 1);

        $shoot->forceFill([
            'cubicasa_sync_started_at' => now()->subMinutes(11),
        ])->save();
        Cache::flush();

        $response = $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/sync", [
            'async' => true,
        ])
            ->assertStatus(202)
            ->assertJsonPath('mode', 'queued');

        $this->assertNotSame($jobId, $response->json('sync.sync_job_id'));
    }

    public function test_cubicasa_sync_without_identifier_records_not_linked_state(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
        ]);

        $this->postJson("/api/integrations/shoots/{$shoot->id}/cubicasa/sync")
            ->assertStatus(409)
            ->assertJsonPath('mode', 'not-linked')
            ->assertJsonPath('sync.sync_status', CubiCasaService::SYNC_STATUS_NOT_LINKED);

        $shoot->refresh();
        $this->assertSame(CubiCasaService::SYNC_STATUS_NOT_LINKED, $shoot->cubicasa_sync_status);
        $this->assertSame('No CubiCasa order linked to this shoot.', $shoot->cubicasa_last_sync_error);
    }
}
