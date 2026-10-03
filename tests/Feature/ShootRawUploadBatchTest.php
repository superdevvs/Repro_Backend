<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, ShootUploadAttempt, ShootRawUploadBatch, User};
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootRawUploadBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Cache, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShootRawUploadBatchTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        Storage::fake('public');
        $actor = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $actor->id,
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED, 'bracket_mode' => 5]);
        $service = Service::factory()->create(['upload_intake_type' => 'photo', 'uses_hdr_brackets' => true]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id,
            'photographer_id' => $actor->id, 'bracket_mode' => 5, 'price' => 1, 'quantity' => 1]);
        Sanctum::actingAs($actor);
        return [$shoot, $actor, $item];
    }

    private function prepare(Shoot $shoot, ShootService $item, string $id, int $total = 5)
    {
        return $this->postJson('/api/shoots/'.$shoot->id.'/upload-batches', [
            'type' => 'raw', 'batch_id' => $id, 'total_files' => $total,
            'service_id' => $item->id, 'upload_lane' => 'photo',
        ]);
    }

    public function test_reservations_are_durable_disjoint_and_scoped_even_before_files_arrive(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $this->prepare($shoot, $item, 'first', 7)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->prepare($shoot, $item, 'second', 5)->assertOk();
        $this->assertSame([0, 7], ShootRawUploadBatch::orderBy('id')->pluck('start_position')->all());
        $this->assertSame([true, true], ShootRawUploadBatch::orderBy('id')->pluck('prepared')->all());
        $this->travel(3)->hours();
        Cache::flush();
        $this->prepare($shoot, $item, 'first', 7)->assertOk();
        $this->assertSame(2, ShootRawUploadBatch::count());
        $other = $item->replicate();
        $other->service_id = Service::factory()->create(['upload_intake_type' => 'photo', 'uses_hdr_brackets' => true])->id;
        $other->save();
        $this->prepare($shoot, $other, 'other-service')->assertOk();
        $this->assertSame(0, ShootRawUploadBatch::where('batch_id', 'other-service')->firstOrFail()->start_position);
        config(['media.raw_parallel_uploads_enabled' => true, 'media.raw_parallel_upload_shoot_ids' => [$shoot->id]]);
        $this->prepare($shoot, $item, 'first', 7)->assertOk()->assertJsonPath('parallel_uploads', 2);
        $this->prepare($shoot, $item, 'first', 8)->assertConflict();
        $this->prepare($shoot, $other, 'first', 7)->assertConflict();
        $shoot->delete();
        $this->assertSame(0, ShootRawUploadBatch::count());
    }

    public function test_preparation_checks_assignment_and_service_lane_before_reserving(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'photographer']));
        $this->prepare($shoot, $item, 'not-assigned')->assertForbidden();
        $this->assertSame(0, ShootRawUploadBatch::count());
        Sanctum::actingAs($actor);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload-batches', [
            'type' => 'raw', 'batch_id' => 'wrong-lane', 'total_files' => 2,
            'service_id' => $item->id, 'upload_lane' => 'video',
        ])->assertUnprocessable();
        $this->assertSame(0, ShootRawUploadBatch::count());
    }

    public function test_out_of_order_completion_and_retry_keep_reserved_brackets(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $this->prepare($shoot, $item, 'out-of-order')->assertOk();
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToTodo')->times(5)->andReturnUsing(
            fn (Shoot $target, UploadedFile $file, int $actorId) => ShootFile::create([
                'shoot_id' => $target->id, 'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(), 'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg', 'file_size' => $file->getSize(),
                'media_type' => 'raw', 'uploaded_by' => $actorId, 'workflow_stage' => ShootFile::STAGE_TODO,
            ])
        );
        app()->instance(ShootMediaStorageService::class, $storage);
        $payload = fn ($i, $key = null) => [
            'files' => [UploadedFile::fake()->create('frame-'.$i.'.jpg', 10, 'image/jpeg')],
            'upload_type' => 'raw', 'upload_lane' => 'photo', 'shoot_service_id' => $item->id,
            'idempotency_key' => $key ?? 'frame-'.$i, 'upload_batch_id' => 'out-of-order',
            'upload_batch_index' => $i, 'upload_batch_total' => 5,
        ];
        foreach ([4, 0, 3, 1, 2] as $index) {
            $this->post('/api/shoots/'.$shoot->id.'/upload', $payload($index), ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('success_count', 1);
            $this->assertDatabaseHas('shoot_files', ['filename' => 'frame-'.$index.'.jpg', 'bracket_group' => 1,
                'sequence' => $index + 1, 'raw_upload_position' => $index]);
        }
        $this->travel(3)->hours(); Cache::flush();
        $this->post('/api/shoots/'.$shoot->id.'/upload', $payload(3), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success_count', 1);
        $this->assertSame(5, $shoot->files()->count());
        $this->post('/api/shoots/'.$shoot->id.'/upload', $payload(3, 'different-identity'), ['Accept' => 'application/json'])
            ->assertConflict();
        $this->assertSame([1, 2, 3, 4, 5], $shoot->files()->orderBy('raw_upload_position')->pluck('sequence')->all());
    }

    public function test_existing_partial_bracket_is_continued_without_reusing_deleted_positions(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
            'filename' => 'last.jpg', 'stored_filename' => 'last.jpg', 'path' => 'shoots/'.$shoot->id.'/last.jpg',
            'file_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $actor->id,
            'media_type' => 'raw', 'workflow_stage' => ShootFile::STAGE_TODO, 'bracket_group' => 2, 'sequence' => 2]);
        $this->prepare($shoot, $item, 'continue', 3)->assertOk();
        $this->assertSame(7, ShootRawUploadBatch::firstOrFail()->start_position);
    }

    public function test_abandoned_compacted_legacy_positions_negotiate_usable_serial_uploads(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $batch = app(ShootRawUploadBatchService::class)->reserve($shoot, $actor, 'legacy', 3, $item->id, 'photo', 5);
        $this->assertFalse($batch->prepared);
        $this->reservedFile($shoot, $actor, $item, $batch, 0, 1, 1);
        $compacted = $this->reservedFile($shoot, $actor, $item, $batch, 2, 1, 2);

        $this->travel(3)->hours();
        Cache::flush();
        config(['media.raw_parallel_uploads_enabled' => true]);
        $this->prepare($shoot, $item, 'legacy', 3)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->prepare($shoot, $item, 'serial-recovery', 2)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->prepare($shoot, $item, 'serial-recovery', 2)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->assertSame(2, ShootRawUploadBatch::count());
        $this->assertFalse($batch->fresh()->prepared);
        $this->assertSame(2, $compacted->fresh()->sequence);
        $recovery = ShootRawUploadBatch::where('batch_id', 'serial-recovery')->firstOrFail();
        $this->assertFalse($recovery->prepared);
        $this->assertSame(3, $recovery->start_position);

        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToTodo')->twice()->andReturnUsing(
            fn (Shoot $target, UploadedFile $file, int $actorId) => ShootFile::create([
                'shoot_id' => $target->id, 'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(), 'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg', 'file_size' => $file->getSize(),
                'media_type' => 'raw', 'uploaded_by' => $actorId, 'workflow_stage' => ShootFile::STAGE_TODO,
            ])
        );
        app()->instance(ShootMediaStorageService::class, $storage);
        foreach ([0, 1] as $index) {
            $this->post('/api/shoots/'.$shoot->id.'/upload', [
                'upload_type' => 'raw', 'upload_lane' => 'photo', 'shoot_service_id' => $item->id,
                'files' => [UploadedFile::fake()->create('recovery-'.$index.'.jpg', 10, 'image/jpeg')],
                'idempotency_key' => 'recovery-'.$index, 'upload_batch_id' => 'serial-recovery',
                'upload_batch_index' => $index, 'upload_batch_total' => 2,
            ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 1);
        }
        $this->assertSame(4, $shoot->files()->count());
        $this->assertSame(4, $shoot->files()->get()->map(fn ($file) => $file->bracket_group.':'.$file->sequence)->unique()->count());
        $this->assertSame([3, 4], $shoot->files()->where('raw_upload_batch_id', $recovery->id)->orderBy('raw_upload_position')->pluck('raw_upload_position')->all());
    }

    public function test_completed_legacy_layout_can_precede_a_new_batch_but_cannot_be_silently_upgraded(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $batch = app(ShootRawUploadBatchService::class)->reserve($shoot, $actor, 'legacy-complete', 2, $item->id, 'photo', 5);
        $this->reservedFile($shoot, $actor, $item, $batch, 0, 1, 1);
        // EXIF separated this frame into a second bracket beyond its raw ordinal.
        $this->reservedFile($shoot, $actor, $item, $batch, 1, 2, 1);
        config(['media.raw_parallel_uploads_enabled' => true]);
        $this->prepare($shoot, $item, 'legacy-complete', 2)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->assertFalse($batch->fresh()->prepared);
        $this->prepare($shoot, $item, 'after-legacy', 3)->assertOk()->assertJsonPath('parallel_uploads', 2);
        $this->assertSame(6, ShootRawUploadBatch::where('batch_id', 'after-legacy')->firstOrFail()->start_position);
        $this->assertSame([1, 2], $shoot->files()->orderBy('id')->pluck('bracket_group')->all());
    }

    public function test_an_existing_legacy_identity_keeps_serial_semantics_idempotently(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $batch = app(ShootRawUploadBatchService::class)->reserve($shoot, $actor, 'legacy-empty', 3, $item->id, 'photo', 5);
        config(['media.raw_parallel_uploads_enabled' => true]);
        $this->prepare($shoot, $item, 'legacy-empty', 3)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->prepare($shoot, $item, 'legacy-empty', 3)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->assertFalse($batch->fresh()->prepared);
        $this->assertSame(1, ShootRawUploadBatch::count());
    }

    public function test_preparation_stays_serial_until_a_cached_predeployment_batch_finishes(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        Cache::put("shoot:{$shoot->id}:raw_upload_batch:old-tab:offset", 0, now()->addHours(2));
        $this->legacyAttempt($shoot, $actor, $item, 'old-tab', 0, 2, ShootUploadAttempt::STATUS_COMPLETED);
        config(['media.raw_parallel_uploads_enabled' => true]);
        $this->prepare($shoot, $item, 'new-tab', 2)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->assertSame(1, ShootRawUploadBatch::count());
        $this->legacyAttempt($shoot, $actor, $item, 'old-tab', 1, 2, ShootUploadAttempt::STATUS_COMPLETED);
        $this->prepare($shoot, $item, 'new-tab', 2)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->assertSame(2, ShootRawUploadBatch::firstOrFail()->start_position);
        $this->prepare($shoot, $item, 'later-tab', 2)->assertOk()->assertJsonPath('parallel_uploads', 2);
    }

    public function test_preparation_does_not_reclaim_an_old_pending_serial_attempt_after_cache_expiry(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $attempt = $this->legacyAttempt($shoot, $actor, $item, 'old-pending-tab', 0, 2, ShootUploadAttempt::STATUS_PENDING);
        $this->travel(2)->days();
        Cache::flush();
        config(['media.raw_parallel_uploads_enabled' => true]);
        $this->prepare($shoot, $item, 'new-tab', 2)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->assertSame(ShootUploadAttempt::STATUS_PENDING, $attempt->fresh()->status);
        $this->assertFalse(ShootRawUploadBatch::firstOrFail()->prepared);
    }

    public function test_predeployment_attempts_remain_guarded_when_a_later_file_has_created_the_ledger(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        Cache::put("shoot:{$shoot->id}:raw_upload_batch:old-tab:offset", 0, now()->addHours(2));
        $this->legacyAttempt($shoot, $actor, $item, 'old-tab', 0, 3, ShootUploadAttempt::STATUS_COMPLETED);
        $this->legacyAttempt($shoot, $actor, $item, 'old-tab', 2, 3, ShootUploadAttempt::STATUS_COMPLETED);
        $batch = app(ShootRawUploadBatchService::class)->reserve($shoot, $actor, 'old-tab', 3, $item->id, 'photo', 5);
        config(['media.raw_parallel_uploads_enabled' => true]);
        $this->prepare($shoot, $item, 'new-tab')->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->assertFalse($batch->fresh()->prepared);
        $pending = $this->legacyAttempt($shoot, $actor, $item, 'old-tab', 1, 3, ShootUploadAttempt::STATUS_PENDING);
        $this->prepare($shoot, $item, 'old-tab', 3)->assertOk()->assertJsonPath('parallel_uploads', 1);
        $pending->update(['status' => ShootUploadAttempt::STATUS_COMPLETED, 'result_file_ids' => [3]]);
        $this->prepare($shoot, $item, 'new-tab')->assertOk()->assertJsonPath('parallel_uploads', 1);
        $this->prepare($shoot, $item, 'later-tab')->assertOk()->assertJsonPath('parallel_uploads', 2);
    }

    public function test_unsafe_legacy_recovery_cannot_enable_regrouping_across_prepared_ranges(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $this->prepare($shoot, $item, 'parallel', 5)->assertOk();
        $prepared = ShootRawUploadBatch::firstOrFail();
        $parallelFile = $this->reservedFile($shoot, $actor, $item, $prepared, 4, 1, 5);
        $legacy = app(ShootRawUploadBatchService::class)->reserve($shoot, $actor, 'legacy', 3, $item->id, 'photo', 5);
        $this->reservedFile($shoot, $actor, $item, $legacy, 7, 2, 2);
        $this->prepare($shoot, $item, 'new-batch')->assertConflict()
            ->assertJsonPath('code', 'legacy_upload_layout_conflict');
        $this->prepare($shoot, $item, 'legacy', 3)->assertConflict();
        app(\App\Services\Shoots\Actions\AutoStackRawFilesAction::class)->execute($shoot);
        $this->assertSame(5, $parallelFile->fresh()->sequence);
        $this->assertSame(2, ShootRawUploadBatch::count());
    }

    private function reservedFile(Shoot $shoot, User $actor, ShootService $item, ShootRawUploadBatch $batch, int $position, int $group, int $sequence): ShootFile
    {
        $file = new ShootFile;
        $file->forceFill(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
            'filename' => 'frame-'.$position.'.jpg', 'stored_filename' => 'frame-'.$position.'.jpg', 'path' => 'shoots/'.$shoot->id.'/frame-'.$position.'.jpg',
            'file_type' => 'image/jpeg', 'file_size' => 100, 'uploaded_by' => $actor->id,
            'media_type' => 'raw', 'workflow_stage' => ShootFile::STAGE_TODO,
            'raw_upload_batch_id' => $batch->id, 'raw_upload_position' => $position,
            'bracket_group' => $group, 'sequence' => $sequence]);
        $file->save();
        return $file;
    }

    private function legacyAttempt(Shoot $shoot, User $actor, ShootService $item, string $id, int $index, int $total, string $status): ShootUploadAttempt
    {
        return ShootUploadAttempt::create(['shoot_id' => $shoot->id, 'actor_id' => $actor->id,
            'idempotency_key' => $id.'-'.$index, 'request_fingerprint' => hash('sha256', $id.'-'.$index),
            'upload_type' => 'raw', 'shoot_service_id' => $item->id, 'upload_batch_id' => $id,
            'upload_batch_index' => $index, 'upload_batch_total' => $total, 'status' => $status,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'result_file_ids' => $status === ShootUploadAttempt::STATUS_COMPLETED ? [$index + 1] : [],
        ]);
    }

    public function test_unbatched_multipart_upload_cannot_take_an_unfinished_reserved_position(): void
    {
        [$shoot, $actor, $item] = $this->fixture();
        $this->prepare($shoot, $item, 'unfinished', 5)->assertOk();
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToTodo')->twice()->andReturnUsing(
            fn (Shoot $target, UploadedFile $file, int $actorId) => ShootFile::create([
                'shoot_id' => $target->id, 'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(), 'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg', 'file_size' => $file->getSize(),
                'media_type' => 'raw', 'uploaded_by' => $actorId, 'workflow_stage' => ShootFile::STAGE_TODO,
            ])
        );
        app()->instance(ShootMediaStorageService::class, $storage);
        $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'upload_type' => 'raw', 'shoot_service_id' => $item->id,
            'files' => [UploadedFile::fake()->create('legacy-a.jpg', 10, 'image/jpeg'), UploadedFile::fake()->create('legacy-b.jpg', 10, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 2);
        $this->assertSame([5, 6], $shoot->files()->orderBy('raw_upload_position')->pluck('raw_upload_position')->all());
        $this->assertSame([1, 2], $shoot->files()->orderBy('raw_upload_position')->pluck('sequence')->all());
        $this->assertSame([2, 2], $shoot->files()->pluck('bracket_group')->all());
    }
}
