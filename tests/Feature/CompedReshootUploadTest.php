<?php

namespace Tests\Feature;

use App\Models\{Category, Service, Shoot, ShootFile, ShootRawUploadBatch, ShootService, User};
use App\Services\{InvoiceService, ShootMediaStorageService};
use App\Services\Shoots\BracketModeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CompedReshootUploadTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_16_160000_enable_comped_reshoot_photo_uploads.php');
    }

    private function fixture(): array
    {
        Queue::fake();
        Storage::fake('public');
        $actor = User::factory()->create(['role' => 'photographer', 'default_bracket_mode' => 5]);
        $service = Service::factory()->create([
            'id' => 88, 'name' => 'Comped Reshoot', 'price' => 0, 'photographer_pay' => 0,
            'category_id' => Category::factory()->create(['name' => 'Reshoot Comp'])->id,
            'upload_intake_type' => 'none', 'uses_hdr_brackets' => false, 'photo_count' => null,
        ]);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $actor->id, 'base_quote' => 0, 'tax_amount' => 0, 'total_quote' => 0,
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        $item = ShootService::create([
            'shoot_id' => $shoot->id, 'service_id' => $service->id, 'photographer_id' => $actor->id,
            'price' => 0, 'photographer_pay' => 0, 'quantity' => 1, 'bracket_mode' => 3,
        ]);
        Sanctum::actingAs($actor);
        return [$shoot, $actor, $service, $item];
    }

    private function prepare(Shoot $shoot, string $id, ?int $scope = null)
    {
        return $this->postJson('/api/shoots/'.$shoot->id.'/upload-batches', [
            'type' => 'raw', 'batch_id' => $id, 'total_files' => 2, 'service_id' => $scope, 'upload_lane' => 'photo',
        ]);
    }

    public function test_assigned_comped_reshoot_accepts_jpeg_and_raw_without_an_extra_service_or_charge(): void
    {
        [$shoot, $actor, $service, $item] = $this->fixture();
        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame('photo', $service->fresh()->upload_intake_type);
        $this->assertNull($service->fresh()->photo_count);
        $this->assertSame(3, app(BracketModeResolver::class)->effectiveBracketMode($item->fresh()));
        $this->assertNull(app(BracketModeResolver::class)->expectedRawForService($item->fresh()));
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        $this->prepare($shoot, 'comped-direct')->assertOk();
        $this->assertSame($item->id, ShootRawUploadBatch::sole()->service_scope);

        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToTodo')->twice()->andReturnUsing(
            fn (Shoot $target, UploadedFile $file, int $actorId) => ShootFile::create([
                'shoot_id' => $target->id, 'filename' => $file->getClientOriginalName(),
                'stored_filename' => $file->getClientOriginalName(), 'path' => 'shoots/'.$target->id.'/todo/'.$file->getClientOriginalName(),
                'file_type' => 'image/jpeg', 'file_size' => $file->getSize(), 'media_type' => 'raw',
                'uploaded_by' => $actorId, 'workflow_stage' => ShootFile::STAGE_TODO,
            ])
        );
        app()->instance(ShootMediaStorageService::class, $storage);
        foreach (['frame.jpg', 'frame.CR3'] as $index => $name) {
            $this->post('/api/shoots/'.$shoot->id.'/upload', [
                'files' => [UploadedFile::fake()->create($name, 10, 'image/jpeg')],
                'upload_type' => 'raw', 'upload_lane' => 'photo', 'upload_batch_id' => 'comped-direct',
                'upload_batch_index' => $index, 'upload_batch_total' => 2, 'idempotency_key' => 'comped-'.$index,
            ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 1);
        }
        $this->assertSame([$item->id], ShootFile::query()->distinct()->pluck('shoot_service_id')->all());
        $this->assertSame([1, 2], ShootFile::orderBy('id')->pluck('sequence')->all());
        $this->assertSame(0.0, (float) $invoice->fresh()->total_amount);
        $this->assertSame(0.0, (float) $item->fresh()->photographer_pay);
        $this->assertSame(0.0, (float) $service->fresh()->price);

        $next = User::factory()->create(['role' => 'photographer']);
        $item->update(['photographer_id' => $next->id]);
        $this->prepare($shoot, 'after-reassignment', $item->id)->assertForbidden();
        Sanctum::actingAs($next);
        $this->prepare($shoot, 'new-assignee', $item->id)->assertOk();
    }

    public function test_non_capture_and_wrong_lane_failures_explain_the_problem_before_reserving(): void
    {
        [$shoot, , $service, $item] = $this->fixture();
        $this->prepare($shoot, 'no-photo-target')->assertUnprocessable()
            ->assertJsonPath('code', 'upload_service_unavailable')
            ->assertJsonPath('message', 'No assigned service on this shoot accepts photo uploads. Ask an admin to add or enable a photo service.');
        $this->assertSame(0, ShootRawUploadBatch::count());
        $this->migration()->up();
        $this->postJson('/api/shoots/'.$shoot->id.'/upload-batches', [
            'type' => 'raw', 'batch_id' => 'wrong-video', 'total_files' => 1, 'service_id' => $item->id, 'upload_lane' => 'video',
        ])->assertUnprocessable()->assertJsonPath('code', 'upload_service_unavailable')
            ->assertJsonPath('message', 'The selected service does not accept video uploads. Choose a compatible service.');
        $this->assertSame(0, ShootRawUploadBatch::count());
    }

    public function test_migration_does_not_reclassify_other_products_or_overwrite_custom_capabilities(): void
    {
        [, , $service] = $this->fixture();
        $service->update(['name' => 'Travel Fee']);
        $this->migration()->up();
        $this->assertSame('none', $service->fresh()->upload_intake_type);
        $service->update(['name' => 'Comped Reshoot', 'upload_intake_type' => 'photo_video']);
        $this->migration()->up();
        $this->assertSame('photo_video', $service->fresh()->upload_intake_type);
        $this->assertFalse($service->fresh()->uses_hdr_brackets);
    }
}
