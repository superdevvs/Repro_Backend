<?php

namespace Tests\Feature;

use App\Jobs\SyncShootIguideJob;
use App\Models\{Shoot, ShootFile, User};
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, Queue};
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class RawSubmissionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): Shoot
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'photographer']);
        Sanctum::actingAs($actor);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $actor->id, 'shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST,
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'saved.nef', 'stored_filename' => 'saved.nef', 'path' => 'shoots/'.$shoot->id.'/todo/saved.nef',
            'file_type' => 'image/x-nikon-nef', 'file_size' => 10, 'media_type' => 'raw', 'uploaded_by' => $actor->id,
            'workflow_stage' => ShootFile::STAGE_TODO, 'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ]);
        return $shoot;
    }

    public function test_sqlite_snapshot_failure_retries_from_fresh_state_then_submits_once(): void
    {
        $shoot = $this->fixture();
        $support = app(ShootMediaMutationSupportService::class);
        $mock = Mockery::mock($support)->makePartial();
        $calls = 0;
        $mock->shouldReceive('refreshMediaCounters')->andReturnUsing(function ($model) use (&$calls, $support) {
            if (++$calls === 1) {
                throw new QueryException('sqlite', 'update shoots', [], new \PDOException('database is locked'));
            }
            return $support->refreshMediaCounters($model);
        });
        app()->instance(ShootMediaMutationSupportService::class, $mock);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertOk()
            ->assertJsonPath('workflow_status_changed', true);
        $this->assertSame(2, $calls);
        $this->assertSame(Shoot::STATUS_UPLOADED, $shoot->fresh()->workflow_status);
        Queue::assertPushed(SyncShootIguideJob::class, 1);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertOk()
            ->assertJsonPath('workflow_status_changed', false);
        Queue::assertPushed(SyncShootIguideJob::class, 1);
    }

    public function test_secondary_enqueue_failure_does_not_report_committed_submission_as_lost(): void
    {
        $shoot = $this->fixture();
        Bus::partialMock()->shouldReceive('dispatch')->with(Mockery::type(SyncShootIguideJob::class))
            ->andThrow(new \RuntimeException('Unavailable integration queue'));
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertOk()
            ->assertJsonPath('workflow_status_changed', true);
        $this->assertSame(Shoot::STATUS_UPLOADED, $shoot->fresh()->workflow_status);
        $this->assertSame(1, $shoot->files()->count());
    }

    public function test_unexpected_failure_preserves_files_and_returns_traceable_safe_error(): void
    {
        $shoot = $this->fixture();
        $support = Mockery::mock(app(ShootMediaMutationSupportService::class))->makePartial();
        $support->shouldReceive('refreshMediaCounters')->andThrow(new \RuntimeException('Private server detail'));
        app()->instance(ShootMediaMutationSupportService::class, $support);
        $response = $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw');
        $response->assertStatus(500)->assertJsonStructure(['correlation_id'])
            ->assertJsonPath('workflow_status_changed', false);
        $this->assertStringNotContainsString('Private server detail', $response->getContent());
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->fresh()->workflow_status);
        $this->assertSame(1, $shoot->files()->count());
    }
}
