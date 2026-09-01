<?php

namespace Tests\Feature;

use App\Jobs\AssembleIguideOfflinePackageJob;
use App\Jobs\FinalizeIguideOfflinePackageJob;
use App\Jobs\ScanShootFileJob;
use App\Models\IguideOfflineUploadChunk;
use App\Models\IguideOfflineUploadSession;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\IguideOfflineChunkUploadService;
use App\Services\IguideOfflinePackageService;
use App\Services\UploadValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class IguideOfflineChunkUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validation_errors_expose_the_resumable_headers_to_the_browser(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'tour.zip',
            'size_bytes' => 100,
        ], [
            'Idempotency-Key' => 'not-a-uuid',
            'Origin' => 'https://reprodashboard.com',
        ])->assertUnprocessable();

        $allowed = (string) $response->headers->get('Access-Control-Allow-Headers');
        $this->assertStringContainsString('Idempotency-Key', $allowed);
        $this->assertStringContainsString('Content-Range', $allowed);
        $this->assertStringContainsString('X-Chunk-SHA256', $allowed);
    }

    #[Test]
    public function staff_can_initiate_and_replay_a_resumable_upload_but_clients_cannot(): void
    {
        $shoot = Shoot::factory()->create();
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);

        $key = (string) Str::uuid();
        $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'tour.zip',
            'size_bytes' => 100,
        ], ['Idempotency-Key' => $key])->assertForbidden();
        $this->assertDatabaseCount('iguide_offline_upload_sessions', 0);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $response = $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'tour.zip',
            'size_bytes' => 100,
        ], ['Idempotency-Key' => $key]);

        $response->assertCreated()
            ->assertJsonPath('upload.status', 'uploading')
            ->assertJsonPath('upload.filename', 'tour.zip')
            ->assertJsonPath('upload.size_bytes', 100)
            ->assertJsonPath('upload.chunk_size_bytes', 5 * 1024 * 1024)
            ->assertJsonPath('upload.total_chunks', 1)
            ->assertJsonPath('upload.received_chunk_indexes', [])
            ->assertJsonPath('upload.received_chunks', []);
        $sessionId = $response->json('upload.id');

        $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'tour.zip',
            'size_bytes' => 100,
        ], ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertJsonPath('upload.id', $sessionId);

        $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'different.zip',
            'size_bytes' => 100,
        ], ['Idempotency-Key' => $key])
            ->assertConflict()
            ->assertJsonPath('error_type', 'idempotency_conflict')
            ->assertJsonPath('upload.id', $sessionId);

        $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'second.zip',
            'size_bytes' => 200,
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('error_type', 'upload_in_progress')
            ->assertJsonPath('upload.id', $sessionId);
    }

    #[Test]
    public function an_in_flight_scan_blocks_a_new_session_but_terminal_records_do_not(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'iguide_data' => [
                'manual_offline_package' => [
                    'id' => 'already-scanning',
                    'upload_id' => 'already-scanning',
                    'status' => 'scanning',
                ],
            ],
        ]);
        Sanctum::actingAs($admin);

        $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'tour.zip',
            'size_bytes' => 100,
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('error_type', 'scan_in_progress');

        $shoot->update([
            'iguide_data' => [
                'manual_offline_package' => [
                    'id' => 'finished',
                    'upload_id' => 'finished',
                    'status' => 'failed',
                ],
            ],
        ]);
        IguideOfflineUploadSession::create($this->sessionAttributes($shoot, $admin, [
            'id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => IguideOfflineUploadSession::STATUS_FAILED,
            'completed_at' => now(),
        ]));

        $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'replacement.zip',
            'size_bytes' => 100,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    #[Test]
    public function raw_chunks_validate_range_length_and_checksum_and_replay_idempotently(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $bytes = 'a small resumable payload';
        $session = $this->initiate($shoot, strlen($bytes));

        $this->putChunk($shoot, $session, 0, $bytes, hash('sha256', 'wrong payload'))
            ->assertUnprocessable()
            ->assertJsonPath('error_type', 'chunk_hash_mismatch')
            ->assertJsonPath('upload.received_bytes', 0);

        $this->putChunk($shoot, $session, 0, $bytes, hash('sha256', $bytes), 'bytes 1-'.strlen($bytes).'/'.strlen($bytes))
            ->assertStatus(416)
            ->assertJsonPath('error_type', 'invalid_content_range');

        $this->putChunk($shoot, $session, 0, $bytes)
            ->assertCreated()
            ->assertJsonPath('upload.received_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunk_indexes.0', 0)
            ->assertJsonPath('upload.received_chunks.0.index', 0)
            ->assertJsonPath('upload.received_chunks.0.size_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunks.0.sha256', hash('sha256', $bytes));
        $this->assertDatabaseCount('iguide_offline_upload_chunks', 1);

        $this->putChunk($shoot, $session, 0, $bytes)
            ->assertOk()
            ->assertJsonPath('upload.received_bytes', strlen($bytes));
        $this->assertDatabaseCount('iguide_offline_upload_chunks', 1);

        $this->putChunk($shoot, $session, 0, str_repeat('x', strlen($bytes)))
            ->assertConflict()
            ->assertJsonPath('error_type', 'chunk_conflict')
            ->assertJsonPath('upload.received_chunks.0.index', 0)
            ->assertJsonPath('upload.received_chunks.0.size_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunks.0.sha256', hash('sha256', $bytes));
        $this->assertDatabaseCount('iguide_offline_upload_chunks', 1);

        $this->getJson($this->uploadUrl($shoot, $session))
            ->assertOk()
            ->assertJsonPath('upload.received_chunk_indexes.0', 0)
            ->assertJsonPath('upload.received_chunks.0.index', 0)
            ->assertJsonPath('upload.received_chunks.0.size_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunks.0.sha256', hash('sha256', $bytes));

        $this->postJson($this->uploadsUrl($shoot), [
            'filename' => 'another-tour.zip',
            'size_bytes' => strlen($bytes),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('error_type', 'upload_in_progress')
            ->assertJsonPath('upload.id', (string) $session->id)
            ->assertJsonPath('upload.received_chunks.0.index', 0)
            ->assertJsonPath('upload.received_chunks.0.size_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunks.0.sha256', hash('sha256', $bytes));
    }

    #[Test]
    public function distinct_chunks_can_arrive_out_of_order_without_corrupting_progress_or_manifest(): void
    {
        Storage::fake('local');
        config()->set('iguide.offline_upload.chunk_size_bytes', 5);

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $bytes = 'abcdefghijklmn';
        $session = $this->initiate($shoot, strlen($bytes));

        $this->putChunk($shoot, $session, 2, substr($bytes, 10, 4))
            ->assertCreated()
            ->assertJsonPath('upload.received_bytes', 4)
            ->assertJsonPath('upload.received_chunk_indexes', [2]);
        $this->putChunk($shoot, $session, 0, substr($bytes, 0, 5))
            ->assertCreated()
            ->assertJsonPath('upload.received_bytes', 9)
            ->assertJsonPath('upload.received_chunk_indexes', [0, 2]);
        $this->putChunk($shoot, $session, 1, substr($bytes, 5, 5))
            ->assertCreated()
            ->assertJsonPath('upload.received_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunk_indexes', [0, 1, 2]);

        $this->putChunk($shoot, $session, 2, substr($bytes, 10, 4))
            ->assertOk()
            ->assertJsonPath('upload.received_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunk_indexes', [0, 1, 2]);

        $this->getJson($this->uploadUrl($shoot, $session))
            ->assertOk()
            ->assertJsonPath('upload.received_bytes', strlen($bytes))
            ->assertJsonPath('upload.received_chunk_indexes', [0, 1, 2])
            ->assertJsonPath('upload.received_chunks.0.index', 0)
            ->assertJsonPath('upload.received_chunks.1.index', 1)
            ->assertJsonPath('upload.received_chunks.2.index', 2);
        $this->assertDatabaseCount('iguide_offline_upload_chunks', 3);
        $this->assertDatabaseHas('iguide_offline_upload_sessions', [
            'id' => $session->id,
            'received_bytes' => strlen($bytes),
        ]);
    }

    #[Test]
    public function completion_reports_missing_chunks_and_dispatches_assembly_only_once(): void
    {
        Storage::fake('local');
        Queue::fake();
        config()->set('iguide.offline_upload.chunk_size_bytes', 5);

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $bytes = 'abcdefghijk';
        $session = $this->initiate($shoot, strlen($bytes));

        $this->putChunk($shoot, $session, 0, substr($bytes, 0, 5))->assertCreated();
        $this->postJson($this->uploadUrl($shoot, $session).'/complete')
            ->assertConflict()
            ->assertJsonPath('error_type', 'upload_incomplete')
            ->assertJsonPath('missing_chunk_indexes.0', 1)
            ->assertJsonPath('missing_chunk_indexes.1', 2);

        $this->putChunk($shoot, $session, 2, substr($bytes, 10, 1))->assertCreated();
        $this->putChunk($shoot, $session, 1, substr($bytes, 5, 5))
            ->assertCreated()
            ->assertJsonPath('upload.received_chunks.0.index', 0)
            ->assertJsonPath('upload.received_chunks.1.index', 1)
            ->assertJsonPath('upload.received_chunks.2.index', 2)
            ->assertJsonPath('upload.received_chunks.1.size_bytes', 5)
            ->assertJsonPath('upload.received_chunks.1.sha256', hash('sha256', substr($bytes, 5, 5)));

        $this->postJson($this->uploadUrl($shoot, $session).'/complete')
            ->assertAccepted()
            ->assertJsonPath('upload.status', 'assembling');
        Queue::assertPushed(AssembleIguideOfflinePackageJob::class, 1);

        $this->postJson($this->uploadUrl($shoot, $session).'/complete')
            ->assertAccepted()
            ->assertJsonPath('upload.status', 'assembling');
        Queue::assertPushed(AssembleIguideOfflinePackageJob::class, 1);
    }

    #[Test]
    public function assembly_lease_excludes_duplicate_jobs_for_the_entire_finalize_section(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $session = IguideOfflineUploadSession::create($this->sessionAttributes($shoot, $admin, [
            'status' => IguideOfflineUploadSession::STATUS_ASSEMBLING,
        ]));
        $uploads = app(IguideOfflineChunkUploadService::class);

        $this->assertTrue($uploads->claimAssembly((string) $session->id, 'worker-one'));
        $this->assertFalse($uploads->claimAssembly((string) $session->id, 'worker-one'));
        $this->assertFalse($uploads->claimAssembly((string) $session->id, 'worker-two'));
        $this->assertGreaterThan(0, $uploads->assemblyClaimRetryAfter((string) $session->id));
        $uploads->releaseAssembly((string) $session->id, 'worker-two');
        $this->assertSame('worker-one', $session->fresh()->assembly_token);

        $competingJob = new AssembleIguideOfflinePackageJob((string) $session->id, 'worker-two');
        $competingJob->handle(
            $uploads,
            app(UploadValidationService::class),
            app(IguideOfflinePackageService::class),
            app(DropboxWorkflowService::class)
        );
        $this->assertDatabaseCount('shoot_files', 0);
        $this->assertSame('worker-one', $session->fresh()->assembly_token);

        $uploads->releaseAssembly((string) $session->id, 'worker-one');
        $this->assertTrue($uploads->claimAssembly((string) $session->id, 'worker-two'));
        $this->assertGreaterThan(
            ($competingJob->tries * $competingJob->timeout) + array_sum($competingJob->backoff),
            $competingJob->uniqueFor
        );
        $this->assertGreaterThan(
            $competingJob->timeout,
            (int) config('queue.connections.database.retry_after')
        );
    }

    #[Test]
    public function assembly_uses_the_existing_private_scan_pipeline_and_preserves_the_previous_ready_package(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create([
            'iguide_data' => [
                'provider_payload' => ['work_order' => 'IG-42'],
                'manual_offline_package' => [
                    'id' => 'old-ready',
                    'upload_id' => 'old-ready',
                    'status' => 'ready',
                    'file_id' => 77,
                    'original_filename' => 'old.zip',
                ],
            ],
        ]);
        Sanctum::actingAs($admin);
        $bytes = $this->zipBytes([
            '9137 Lakeland Valley/Index.HTML' => '<!doctype html><title>Tour</title>',
            '9137 Lakeland Valley/assets/app.js' => 'console.log("tour")',
        ]);
        $session = $this->initiate($shoot, strlen($bytes));
        $this->putChunk($shoot, $session, 0, $bytes)->assertCreated();
        $this->postJson($this->uploadUrl($shoot, $session).'/complete')->assertAccepted();

        $job = new AssembleIguideOfflinePackageJob((string) $session->getKey());
        $job->handle(
            app(IguideOfflineChunkUploadService::class),
            app(UploadValidationService::class),
            app(IguideOfflinePackageService::class),
            app(DropboxWorkflowService::class)
        );

        $session->refresh();
        $this->assertSame(IguideOfflineUploadSession::STATUS_SCANNING, $session->status);
        $file = ShootFile::query()->where('shoot_id', $shoot->id)->sole();
        $this->assertSame($file->id, $session->shoot_file_id);
        $this->assertTrue($file->isIguideOfflinePackage());
        $this->assertSame('9137 Lakeland Valley/Index.HTML', data_get($file->metadata, 'index_entry_path'));
        $this->assertSame(ShootFile::SCAN_STATUS_QUARANTINED, $file->scan_status);
        $this->assertStringStartsWith("secure/iguide-packages/{$shoot->id}/", $file->path);
        Storage::disk('local')->assertExists($file->path);
        Queue::assertPushed(ScanShootFileJob::class, fn (ScanShootFileJob $scan): bool => $scan->shootFileId === $file->id);

        $lifecycle = data_get($shoot->fresh()->iguide_data, 'manual_offline_package');
        $this->assertSame((string) $session->id, $lifecycle['upload_id']);
        $this->assertSame('scanning', $lifecycle['status']);
        $this->assertSame('9137 Lakeland Valley/Index.HTML', $lifecycle['index_entry_path']);
        $this->assertSame(77, $lifecycle['previous_ready']['file_id']);
        $this->assertSame('IG-42', data_get($shoot->fresh()->iguide_data, 'provider_payload.work_order'));
        $this->assertDatabaseCount('iguide_offline_upload_chunks', 0);

        // A replayed queue job is a no-op and cannot create another ShootFile.
        $job->handle(
            app(IguideOfflineChunkUploadService::class),
            app(UploadValidationService::class),
            app(IguideOfflinePackageService::class),
            app(DropboxWorkflowService::class)
        );
        $this->assertDatabaseCount('shoot_files', 1);

        $file->update([
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
            'scan_result' => 'all members clean',
            'scanned_at' => now(),
        ]);
        (new FinalizeIguideOfflinePackageJob($file->id))->handle(
            app(IguideOfflinePackageService::class),
            app(DropboxWorkflowService::class)
        );
        $this->assertSame(IguideOfflineUploadSession::STATUS_READY, $session->fresh()->status);
        app(IguideOfflinePackageService::class)->markFailed($file, 'late failure callback');
        $this->assertSame(IguideOfflineUploadSession::STATUS_READY, $session->fresh()->status);
        $this->assertSame('ready', data_get($shoot->fresh()->iguide_data, 'manual_offline_package.status'));
        $this->postJson($this->uploadUrl($shoot, $session).'/complete')
            ->assertOk()
            ->assertJsonPath('upload.status', 'ready')
            ->assertJsonPath('manual_offline_package.file_id', $file->id);

        $this->getJson($this->uploadUrl($shoot, $session))
            ->assertOk()
            ->assertJsonPath('upload.status', 'ready')
            ->assertJsonPath('manual_offline_package.file_id', $file->id)
            ->assertJsonPath('iguide_data.provider_payload.work_order', 'IG-42');
    }

    #[Test]
    public function a_failed_resumable_replacement_restores_the_prior_download_but_marks_the_new_session_failed(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $previousReady = [
            'id' => 'old-ready',
            'upload_id' => 'old-ready',
            'status' => 'ready',
            'file_id' => 77,
            'original_filename' => 'known-good.zip',
            'download_url' => '/api/shoots/999/media/77/download',
            'ready_at' => now()->subDay()->toIso8601String(),
        ];
        $shoot = Shoot::factory()->create([
            'iguide_data' => ['manual_offline_package' => $previousReady],
        ]);
        Sanctum::actingAs($admin);
        $bytes = $this->zipBytes([
            'tour/index.html' => '<html>tour</html>',
            'tour/app.js' => 'console.log("tour")',
        ]);
        $session = $this->initiate($shoot, strlen($bytes));
        $this->putChunk($shoot, $session, 0, $bytes)->assertCreated();
        $this->postJson($this->uploadUrl($shoot, $session).'/complete')->assertAccepted();
        (new AssembleIguideOfflinePackageJob((string) $session->id))->handle(
            app(IguideOfflineChunkUploadService::class),
            app(UploadValidationService::class),
            app(IguideOfflinePackageService::class),
            app(DropboxWorkflowService::class)
        );

        $file = ShootFile::query()->where('shoot_id', $shoot->id)->sole();
        app(IguideOfflinePackageService::class)->markFailed($file, 'Eicar-Test-Signature');

        $restored = data_get($shoot->fresh()->iguide_data, 'manual_offline_package');
        $this->assertSame('ready', $restored['status']);
        $this->assertSame(77, $restored['file_id']);
        $this->assertSame('known-good.zip', $restored['original_filename']);
        $this->assertSame('/api/shoots/999/media/77/download', $restored['download_url']);
        $this->assertSame((string) $session->id, $restored['last_replacement_failure']['upload_id']);
        $this->assertSame($file->id, $restored['last_replacement_failure']['file_id']);
        $this->assertSame('Eicar-Test-Signature', $restored['last_replacement_failure']['error']);

        $session->refresh();
        $this->assertSame(IguideOfflineUploadSession::STATUS_FAILED, $session->status);
        $this->assertSame($file->id, $session->shoot_file_id);
        $this->assertSame('Eicar-Test-Signature', $session->error);
        $this->getJson($this->uploadUrl($shoot, $session))
            ->assertOk()
            ->assertJsonPath('upload.status', 'failed')
            ->assertJsonPath('upload.error', 'Eicar-Test-Signature')
            ->assertJsonMissingPath('manual_offline_package');
    }

    #[Test]
    public function repeated_successful_replacements_remove_superseded_private_blobs_only_after_the_new_package_is_ready(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $packages = app(IguideOfflinePackageService::class);
        $inspection = static fn (string $filename): array => [
            'original_filename' => $filename,
            'size_bytes' => 100,
            'sha256' => str_repeat('a', 64),
            'entry_count' => 2,
            'expanded_size_bytes' => 200,
            'wrapper_directory' => 'tour',
        ];
        $makeCleanPackage = static function (string $uploadId, string $filename) use ($shoot, $admin): ShootFile {
            $path = "secure/iguide-packages/{$shoot->id}/{$filename}";
            Storage::disk('local')->put($path, "private package {$uploadId}");

            return ShootFile::create([
                'shoot_id' => $shoot->id,
                'filename' => $filename,
                'stored_filename' => $filename,
                'path' => $path,
                'file_type' => 'application/zip',
                'file_size' => 100,
                'media_type' => ShootFile::MEDIA_TYPE_IGUIDE,
                'uploaded_by' => $admin->id,
                'workflow_stage' => ShootFile::STAGE_ARCHIVED,
                'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
                'metadata' => [
                    'kind' => ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND,
                    'upload_id' => $uploadId,
                ],
            ]);
        };

        $packages->beginUpload($shoot, $inspection('one.zip'), $admin, 'upload-one');
        $first = $makeCleanPackage('upload-one', 'one.zip');
        $packages->markReady($first);

        $packages->beginUpload($shoot, $inspection('two.zip'), $admin, 'upload-two');
        Storage::disk('local')->assertExists($first->path);
        $this->assertDatabaseHas('shoot_files', ['id' => $first->id]);
        $second = $makeCleanPackage('upload-two', 'two.zip');
        $secondLifecycle = $packages->markReady($second);

        Storage::disk('local')->assertMissing($first->path);
        $this->assertDatabaseMissing('shoot_files', ['id' => $first->id]);
        $this->assertSame($second->id, $secondLifecycle['file_id']);
        $this->assertSame('removed', $secondLifecycle['last_superseded_package']['cleanup_status']);
        $this->assertTrue($secondLifecycle['last_superseded_package']['row_deleted']);

        // Finalization is idempotent and can never clean up the current package.
        $packages->markReady($second);
        Storage::disk('local')->assertExists($second->path);
        $this->assertDatabaseHas('shoot_files', ['id' => $second->id]);

        // External deletion is deliberately not guessed through a private
        // Dropbox API. A mirrored predecessor keeps a blocked tombstone row,
        // while its private local blob is still reclaimed and visibly recorded.
        $second->forceFill([
            'dropbox_path' => '/shoots/two.zip',
            'dropbox_file_id' => 'id:two',
        ])->save();
        $packages->beginUpload($shoot, $inspection('three.zip'), $admin, 'upload-three');
        Storage::disk('local')->assertExists($second->path);
        $third = $makeCleanPackage('upload-three', 'three.zip');
        $thirdLifecycle = $packages->markReady($third);

        Storage::disk('local')->assertMissing("secure/iguide-packages/{$shoot->id}/two.zip");
        $supersededSecond = $second->fresh();
        $this->assertNotNull($supersededSecond);
        $this->assertSame("secure/iguide-packages/{$shoot->id}/two.zip", $supersededSecond->path);
        $this->assertSame(ShootFile::SCAN_STATUS_FAILED, $supersededSecond->scan_status);
        $this->assertSame('superseded', $supersededSecond->scan_result);
        $this->assertTrue((bool) $supersededSecond->is_hidden);
        $this->assertSame($third->id, data_get($supersededSecond->metadata, 'superseded.by_file_id'));
        $this->assertSame(
            'external_mirror_retained',
            $thirdLifecycle['last_superseded_package']['cleanup_status']
        );
        $this->assertTrue($thirdLifecycle['last_superseded_package']['dropbox_mirror_retained']);

        $packages->markReady($third);
        Storage::disk('local')->assertExists($third->path);
        $this->assertSame(2, ShootFile::query()->where('shoot_id', $shoot->id)->count());
    }

    #[Test]
    public function storage_failure_restores_previous_ready_and_fails_only_the_new_session(): void
    {
        $admin = User::factory()->admin()->create();
        $previousReady = [
            'id' => 'old-ready',
            'upload_id' => 'old-ready',
            'status' => 'ready',
            'file_id' => 88,
            'original_filename' => 'old.zip',
            'download_url' => '/api/shoots/1/media/88/download',
        ];
        $shoot = Shoot::factory()->create([
            'iguide_data' => ['manual_offline_package' => $previousReady],
        ]);
        $session = IguideOfflineUploadSession::create($this->sessionAttributes($shoot, $admin, [
            'id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => IguideOfflineUploadSession::STATUS_ASSEMBLING,
        ]));
        $inspection = [
            'original_filename' => 'replacement.zip',
            'size_bytes' => 100,
            'sha256' => str_repeat('a', 64),
            'entry_count' => 2,
            'expanded_size_bytes' => 200,
            'wrapper_directory' => 'tour',
        ];
        $packages = app(IguideOfflinePackageService::class);
        $packages->beginUpload($shoot, $inspection, $admin, (string) $session->id);
        $packages->markUploadFailed($shoot->id, (string) $session->id, 'private storage unavailable');

        $restored = data_get($shoot->fresh()->iguide_data, 'manual_offline_package');
        $this->assertSame('ready', $restored['status']);
        $this->assertSame(88, $restored['file_id']);
        $this->assertSame('private storage unavailable', $restored['last_replacement_failure']['error']);
        $this->assertSame((string) $session->id, $restored['last_replacement_failure']['upload_id']);
        $this->assertSame(IguideOfflineUploadSession::STATUS_FAILED, $session->fresh()->status);
        $this->assertSame('private storage unavailable', $session->fresh()->error);
    }

    #[Test]
    public function unsafe_assembled_zip_fails_without_changing_the_existing_ready_lifecycle(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $ready = [
            'id' => 'old-ready',
            'upload_id' => 'old-ready',
            'status' => 'ready',
            'file_id' => 77,
            'original_filename' => 'old.zip',
        ];
        $shoot = Shoot::factory()->create(['iguide_data' => ['manual_offline_package' => $ready]]);
        Sanctum::actingAs($admin);
        $bytes = $this->zipBytes([
            'tour/index.html' => '<html>tour</html>',
            'tour/shell.php' => '<?php echo "unsafe";',
        ]);
        $session = $this->initiate($shoot, strlen($bytes));
        $this->putChunk($shoot, $session, 0, $bytes)->assertCreated();
        $this->postJson($this->uploadUrl($shoot, $session).'/complete')->assertAccepted();

        (new AssembleIguideOfflinePackageJob((string) $session->id))->handle(
            app(IguideOfflineChunkUploadService::class),
            app(UploadValidationService::class),
            app(IguideOfflinePackageService::class),
            app(DropboxWorkflowService::class)
        );

        $session->refresh();
        $this->assertSame(IguideOfflineUploadSession::STATUS_FAILED, $session->status);
        $this->assertFalse($session->retryable);
        $this->assertStringContainsString('dangerous', strtolower((string) $session->error));
        $this->assertSame($ready, data_get($shoot->fresh()->iguide_data, 'manual_offline_package'));
        $this->assertDatabaseCount('shoot_files', 0);
        $this->assertDatabaseCount('iguide_offline_upload_chunks', 0);
    }

    #[Test]
    public function uploading_sessions_can_be_cancelled_and_expired_staging_is_pruned(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);
        $bytes = 'cancel me';
        $session = $this->initiate($shoot, strlen($bytes));
        $this->putChunk($shoot, $session, 0, $bytes)->assertCreated();
        $chunkPath = IguideOfflineUploadChunk::query()->sole()->storage_path;
        Storage::disk('local')->assertExists($chunkPath);

        $this->deleteJson($this->uploadUrl($shoot, $session))->assertNoContent();
        $this->deleteJson($this->uploadUrl($shoot, $session))->assertNoContent();
        $this->assertSame(IguideOfflineUploadSession::STATUS_CANCELLED, $session->fresh()->status);
        Storage::disk('local')->assertMissing($chunkPath);
        $this->assertDatabaseCount('iguide_offline_upload_chunks', 0);

        $secondShoot = Shoot::factory()->create();
        $expired = $this->initiate($secondShoot, strlen($bytes));
        $this->putChunk($secondShoot, $expired, 0, $bytes)->assertCreated();
        $expiredChunk = IguideOfflineUploadChunk::query()
            ->where('upload_session_id', $expired->id)
            ->sole();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        // Starting a fresh session eagerly expires and removes the abandoned
        // private chunks; it does not wait for the scheduled retention sweep.
        $replacement = $this->initiate($secondShoot, strlen($bytes));
        $this->assertSame(IguideOfflineUploadSession::STATUS_EXPIRED, $expired->fresh()->status);
        Storage::disk('local')->assertMissing($expiredChunk->storage_path);
        $this->assertSame(IguideOfflineUploadSession::STATUS_UPLOADING, $replacement->status);

        $thirdShoot = Shoot::factory()->create();
        $pruned = $this->initiate($thirdShoot, strlen($bytes));
        $this->putChunk($thirdShoot, $pruned, 0, $bytes)->assertCreated();
        $pruned->forceFill(['expires_at' => now()->subMinute()])->save();
        $orphanId = (string) Str::uuid();
        $orphanPath = "secure/iguide-upload-sessions/{$orphanId}/chunks/0.part";
        Storage::disk('local')->put($orphanPath, 'orphaned private chunk');
        Storage::disk('local')->assertExists($orphanPath);

        $result = app(IguideOfflineChunkUploadService::class)->prune();
        $this->assertSame(1, $result['expired']);
        $this->assertSame(1, $result['orphan_pruned']);
        $this->assertSame(IguideOfflineUploadSession::STATUS_EXPIRED, $pruned->fresh()->status);
        Storage::disk('local')->assertMissing($orphanPath);
    }

    #[Test]
    public function stale_scanning_sessions_fail_closed_without_duplicate_dispatch_or_hiding_the_previous_ready_package(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $previousReady = [
            'id' => 'previous-ready',
            'upload_id' => 'previous-ready',
            'status' => 'ready',
            'file_id' => 55,
            'original_filename' => 'previous.zip',
            'download_url' => '/api/shoots/1/media/55/download',
        ];

        $shoot = Shoot::factory()->create();
        $session = IguideOfflineUploadSession::create($this->sessionAttributes($shoot, $admin, [
            'status' => IguideOfflineUploadSession::STATUS_SCANNING,
        ]));
        $shoot->update(['iguide_data' => [
            'manual_offline_package' => [
                'id' => (string) $session->id,
                'upload_id' => (string) $session->id,
                'status' => 'scanning',
                'original_filename' => 'replacement.zip',
                'previous_ready' => $previousReady,
            ],
        ]]);
        $file = ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'replacement.zip',
            'stored_filename' => 'replacement.zip',
            'path' => "secure/iguide-packages/{$shoot->id}/replacement.zip",
            'file_type' => 'application/zip',
            'file_size' => 100,
            'media_type' => ShootFile::MEDIA_TYPE_IGUIDE,
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_ARCHIVED,
            'scan_status' => ShootFile::SCAN_STATUS_QUARANTINED,
            'metadata' => [
                'kind' => ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND,
                'upload_id' => (string) $session->id,
            ],
        ]);
        $session->update(['shoot_file_id' => $file->id]);
        DB::table('iguide_offline_upload_sessions')->where('id', $session->id)->update([
            'updated_at' => now()->subMinutes(361),
            'last_activity_at' => now()->subMinutes(361),
        ]);

        $result = app(IguideOfflineChunkUploadService::class)->prune();
        $this->assertSame(0, $result['scan_reconciled']);
        $this->assertSame(1, $result['scan_failed']);
        Queue::assertNotPushed(ScanShootFileJob::class);
        $failedFile = $file->fresh();
        $this->assertSame(ShootFile::SCAN_STATUS_FAILED, $failedFile->scan_status);
        $this->assertSame('scan_recovery_timeout', $failedFile->scan_result);
        $this->assertSame(IguideOfflineUploadSession::STATUS_FAILED, $session->fresh()->status);
        $timedOutRestored = data_get($shoot->fresh()->iguide_data, 'manual_offline_package');
        $this->assertSame('ready', $timedOutRestored['status']);
        $this->assertSame(55, $timedOutRestored['file_id']);
        $this->assertStringContainsString(
            'did not complete',
            $timedOutRestored['last_replacement_failure']['error']
        );

        $missingShoot = Shoot::factory()->create();
        $missingSession = IguideOfflineUploadSession::create($this->sessionAttributes($missingShoot, $admin, [
            'status' => IguideOfflineUploadSession::STATUS_SCANNING,
        ]));
        $missingShoot->update(['iguide_data' => [
            'manual_offline_package' => [
                'id' => (string) $missingSession->id,
                'upload_id' => (string) $missingSession->id,
                'status' => 'scanning',
                'original_filename' => 'missing.zip',
                'previous_ready' => $previousReady,
            ],
        ]]);
        DB::table('iguide_offline_upload_sessions')->where('id', $missingSession->id)->update([
            'updated_at' => now()->subMinutes(361),
            'last_activity_at' => now()->subMinutes(361),
        ]);

        $missingResult = app(IguideOfflineChunkUploadService::class)->prune();
        $this->assertSame(1, $missingResult['scan_failed']);
        $this->assertSame(IguideOfflineUploadSession::STATUS_FAILED, $missingSession->fresh()->status);
        $restored = data_get($missingShoot->fresh()->iguide_data, 'manual_offline_package');
        $this->assertSame('ready', $restored['status']);
        $this->assertSame(55, $restored['file_id']);
        $this->assertSame(
            (string) $missingSession->id,
            $restored['last_replacement_failure']['upload_id']
        );
        $this->assertStringContainsString(
            'could not be found',
            $restored['last_replacement_failure']['error']
        );

    }

    private function initiate(Shoot $shoot, int $sizeBytes): IguideOfflineUploadSession
    {
        $response = $this->postJson($this->uploadsUrl($shoot), [
            'filename' => '9137 Lakeland Valley - offline_en.zip',
            'size_bytes' => $sizeBytes,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return IguideOfflineUploadSession::findOrFail($response->json('upload.id'));
    }

    private function putChunk(
        Shoot $shoot,
        IguideOfflineUploadSession $session,
        int $index,
        string $bytes,
        ?string $sha256 = null,
        ?string $contentRange = null
    ) {
        $start = $index * (int) $session->chunk_size_bytes;
        $end = $start + strlen($bytes) - 1;
        $range = $contentRange ?? "bytes {$start}-{$end}/{$session->size_bytes}";

        return $this->call(
            'PUT',
            $this->uploadUrl($shoot, $session)."/chunks/{$index}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/octet-stream',
                'CONTENT_LENGTH' => (string) strlen($bytes),
                'HTTP_CONTENT_RANGE' => $range,
                'HTTP_X_CHUNK_SHA256' => $sha256 ?? hash('sha256', $bytes),
            ],
            $bytes
        );
    }

    private function uploadsUrl(Shoot $shoot): string
    {
        return "/api/integrations/shoots/{$shoot->id}/iguide/offline-package/uploads";
    }

    private function uploadUrl(Shoot $shoot, IguideOfflineUploadSession $session): string
    {
        return $this->uploadsUrl($shoot).'/'.$session->id;
    }

    /** @param array<string,string> $entries */
    private function zipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iguide-resumable-test-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        $this->assertIsString($bytes);

        return $bytes;
    }

    /** @param array<string,mixed> $overrides */
    private function sessionAttributes(Shoot $shoot, User $user, array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid(),
            'shoot_id' => $shoot->id,
            'user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'original_filename' => 'old.zip',
            'size_bytes' => 1,
            'chunk_size_bytes' => 5 * 1024 * 1024,
            'total_chunks' => 1,
            'received_bytes' => 0,
            'status' => IguideOfflineUploadSession::STATUS_FAILED,
            'retryable' => false,
            'last_activity_at' => now(),
            'expires_at' => now()->addDay(),
        ], $overrides);
    }
}
