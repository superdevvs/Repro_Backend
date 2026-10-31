<?php

namespace Tests\Feature;

use App\Jobs\GenerateShootMediaArchiveJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShootMediaDeletionIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Real commits are essential here: RefreshDatabase's surrounding test
        // transaction would hide the distinction between savepoint and commit.
        $this->artisan('migrate:fresh')->run();
        $this->app[Kernel::class]->setArtisan(null);
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        config(['media.tiered_storage_enabled' => false, 'media.dual_write' => false, 'media.r2_only' => false]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_imported_media_can_be_deleted_without_destroying_its_provenance(): void
    {
        [$shoot, $file] = $this->createMedia();
        $history = $this->createImportHistory($file);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/bulk-delete', ['ids' => [$file->id]])
            ->assertOk()->assertJsonPath('deleted_ids', [$file->id])->assertJsonPath('failed_ids', [])
            ->assertJsonPath('counts.edited_photo_count', 0);

        $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
        $this->assertEquals($history + ['shoot_file_id' => null], (array) DB::table('september_import_assets')->first());
        Storage::disk('local')->assertMissing([$file->storage_path, $file->web_path]);
    }

    public function test_another_restrictive_reference_rolls_back_the_detach_and_preserves_every_asset(): void
    {
        [$shoot, $file] = $this->createMedia();
        $history = $this->createImportHistory($file);
        Schema::create('media_delete_blockers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_file_id')->constrained('shoot_files');
        });
        DB::table('media_delete_blockers')->insert(['shoot_file_id' => $file->id]);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/bulk-delete', ['ids' => [$file->id]])
            ->assertStatus(207)->assertJsonPath('deleted_ids', [])->assertJsonPath('failed_ids', [$file->id]);

        $this->assertDatabaseHas('shoot_files', ['id' => $file->id]);
        $this->assertEquals($history + ['shoot_file_id' => $file->id], (array) DB::table('september_import_assets')->first());
        Storage::disk('local')->assertExists([$file->storage_path, $file->web_path]);
    }

    public function test_outer_rollback_preserves_bytes_and_does_not_dispatch_deleted_file_jobs(): void
    {
        [$shoot, $file] = $this->createMedia();
        $this->createImportHistory($file);
        DB::table('shoots')->where('id', $shoot->id)->update(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $shoot = $shoot->fresh();
        Queue::fake();
        $support = app(ShootMediaMutationSupportService::class);
        $revision = $support->currentMediaRevision($shoot);

        DB::beginTransaction();
        try {
            $support->deleteFile($shoot, $file);
            $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
            Storage::disk('local')->assertExists([$file->storage_path, $file->web_path]);
            $this->assertSame($revision, $support->currentMediaRevision($shoot));
            Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseHas('shoot_files', ['id' => $file->id]);
        $this->assertDatabaseHas('september_import_assets', ['shoot_file_id' => $file->id]);
        Storage::disk('local')->assertExists([$file->storage_path, $file->web_path]);
        Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
    }

    public function test_a_cancelled_model_deletion_keeps_bytes_and_import_reference(): void
    {
        [$shoot, $file] = $this->createMedia();
        $this->createImportHistory($file);
        ShootFile::deleting(fn (ShootFile $candidate) => $candidate->id === $file->id ? false : null);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/bulk-delete', ['ids' => [$file->id]])
            ->assertStatus(207)->assertJsonPath('failed_ids', [$file->id])->assertJsonPath('deleted_ids', []);

        $this->assertDatabaseHas('shoot_files', ['id' => $file->id]);
        $this->assertDatabaseHas('september_import_assets', ['shoot_file_id' => $file->id]);
        Storage::disk('local')->assertExists([$file->storage_path, $file->web_path]);
    }

    public function test_a_locked_delete_is_retried_with_its_import_detach_rolled_back(): void
    {
        [$shoot, $file] = $this->createMedia();
        $this->createImportHistory($file);
        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts, $file) {
            if (! str_starts_with(strtolower(trim($query->sql)), 'delete from "shoot_files"')) {
                return;
            }
            $attempts++;
            Storage::disk('local')->assertExists([$file->storage_path, $file->web_path]);
            if ($attempts === 1) {
                // The statement ran, but this exception rolls back its whole
                // transaction, matching a SQLite write refusal's net effect.
                throw new QueryException('sqlite', $query->sql, $query->bindings, new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
            }
        });

        $this->postJson('/api/shoots/'.$shoot->id.'/media/bulk-delete', ['ids' => [$file->id]])
            ->assertOk()->assertJsonPath('deleted_ids', [$file->id])->assertJsonPath('failed_ids', []);

        $this->assertSame(2, $attempts);
        $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
        $this->assertDatabaseHas('september_import_assets', ['shoot_file_id' => null]);
        Storage::disk('local')->assertMissing([$file->storage_path, $file->web_path]);
    }

    public function test_cleanup_and_observers_run_only_after_the_outer_commit(): void
    {
        [$shoot, $file] = $this->createMedia();
        DB::table('shoots')->where('id', $shoot->id)->update(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $shoot = $shoot->fresh();
        Queue::fake();
        $support = app(ShootMediaMutationSupportService::class);
        $revision = $support->currentMediaRevision($shoot);

        DB::beginTransaction();
        try {
            $support->deleteFile($shoot, $file);
            Storage::disk('local')->assertExists([$file->storage_path, $file->web_path]);
            Queue::assertNotPushed(GenerateShootMediaArchiveJob::class);
            $this->assertSame($revision, $support->currentMediaRevision($shoot));
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        Storage::disk('local')->assertMissing([$file->storage_path, $file->web_path]);
        Queue::assertPushed(GenerateShootMediaArchiveJob::class, fn ($job) => $job->shootId === $shoot->id && $job->type === 'edited');
        $this->assertGreaterThan($revision, $support->currentMediaRevision($shoot));
    }

    public function test_asset_cleanup_failure_does_not_report_a_committed_deletion_as_failed(): void
    {
        [$shoot, $file] = $this->createMedia();
        $support = Mockery::mock(ShootMediaMutationSupportService::class, [app(ShootAuthorizationSupport::class)])->makePartial();
        $support->shouldReceive('deleteStoredAssets')->once()->andThrow(new \RuntimeException('Storage unavailable'));
        $this->app->instance(ShootMediaMutationSupportService::class, $support);

        $this->postJson('/api/shoots/'.$shoot->id.'/media/bulk-delete', ['ids' => [$file->id]])
            ->assertOk()->assertJsonPath('deleted_ids', [$file->id])->assertJsonPath('failed_ids', []);

        $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
        Storage::disk('local')->assertExists([$file->storage_path, $file->web_path]);
    }

    public function test_audit_failure_does_not_put_a_committed_deletion_in_failed_ids(): void
    {
        [$shoot, $file] = $this->createMedia();
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit writer unavailable'));
        });

        $this->postJson('/api/shoots/'.$shoot->id.'/media/bulk-delete', ['ids' => [$file->id]])
            ->assertOk()->assertJsonPath('deleted_ids', [$file->id])->assertJsonPath('failed_ids', []);

        $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing([$file->storage_path, $file->web_path]);
    }

    public function test_single_delete_audit_failure_still_reports_the_committed_deletion(): void
    {
        [$shoot, $file] = $this->createMedia();
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit writer unavailable'));
        });

        $this->deleteJson('/api/shoots/'.$shoot->id.'/media/'.$file->id)
            ->assertOk()->assertJsonPath('deleted_id', $file->id);

        $this->assertDatabaseMissing('shoot_files', ['id' => $file->id]);
        Storage::disk('local')->assertMissing([$file->storage_path, $file->web_path]);
    }

    private function createMedia(): array
    {
        $shoot = Shoot::factory()->create(['status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED]);
        $file = ShootFile::create([
            'shoot_id' => $shoot->id,
            'uploaded_by' => auth()->id(),
            'filename' => 'front.jpg',
            'stored_filename' => 'front.jpg',
            'path' => 'shoots/'.$shoot->id.'/completed/front.jpg',
            'storage_path' => 'shoots/'.$shoot->id.'/completed/front.jpg',
            'web_path' => 'shoots/'.$shoot->id.'/web/front.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 10,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'media_type' => 'edited',
        ]);
        Storage::disk('local')->put($file->storage_path, 'original');
        Storage::disk('local')->put($file->web_path, 'preview');

        return [$shoot, $file];
    }

    private function createImportHistory(ShootFile $file): array
    {
        // Match the production import ledger's nullable, restrictive file FK.
        Schema::create('september_import_assets', function (Blueprint $table) {
            $table->string('external_key');
            $table->string('snapshot_at');
            $table->string('source_asset_key');
            $table->foreignId('shoot_id')->constrained('shoots');
            $table->foreignId('shoot_file_id')->nullable()->constrained('shoot_files');
            $table->string('kind');
            $table->string('path');
            $table->string('sha256');
            $table->integer('bytes');
            $table->text('metadata');
            $table->primary(['external_key', 'snapshot_at', 'source_asset_key']);
        });
        $history = [
            'external_key' => 'legacy-listing', 'snapshot_at' => '2026-09-01', 'source_asset_key' => 'photo-1',
            'shoot_id' => $file->shoot_id, 'kind' => 'edited', 'path' => $file->storage_path,
            'sha256' => hash('sha256', 'original'), 'bytes' => 8, 'metadata' => '{"source":"legacy"}',
        ];
        DB::table('september_import_assets')->insert($history + ['shoot_file_id' => $file->id]);

        return $history;
    }
}
