<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdditionalEditedUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clamav.scan_on_upload' => false]);
        Queue::fake();
        Storage::fake('public');
    }

    public static function productionRoles(): array
    {
        return [['admin'], ['superadmin'], ['editing_manager']];
    }

    private function delivered(): array
    {
        $shoot = Shoot::factory()->create(['status' => 'delivered', 'workflow_status' => 'delivered', 'payment_status' => 'unpaid']);
        $service = Service::factory()->unbracketedPhoto(26)->create(['requires_editing' => true]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id,
            'quantity' => 1, 'price' => 175, 'workflow_status' => 'delivered', 'delivery_status' => 'delivered',
            'delivered_at' => now()->subDay()]);
        for ($i = 0; $i < 28; $i++) {
            ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $item->id,
                'filename' => "delivered-{$i}.jpg", 'stored_filename' => "delivered-{$i}.jpg", 'path' => "delivered-{$i}.jpg",
                'file_type' => 'image/jpeg', 'file_size' => 100, 'media_type' => 'edited', 'workflow_stage' => 'verified',
                'uploaded_by' => $shoot->photographer_id]);
        }
        return [$shoot, $item];
    }

    #[DataProvider('productionRoles')]
    public function test_staff_can_add_edited_files_above_required_count_without_reopening_delivery(string $role): void
    {
        [$shoot, $item] = $this->delivered();
        $actor = User::factory()->create(['role' => $role]);
        $oldFiles = $shoot->files()->orderBy('id')->get()->map->getRawOriginal()->all();
        $deliveredAt = $item->getRawOriginal('delivered_at');
        $this->actingAs($actor)->post("/api/shoots/{$shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('additional.jpg', 8, 8)],
            'upload_type' => 'edited', 'shoot_service_id' => $item->id,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 1)
            ->assertJsonPath('uploaded_files.0.shoot_service_id', $item->id);

        $this->assertSame(29, $shoot->files()->count());
        $this->assertSame($oldFiles, $shoot->files()->where('filename', '!=', 'additional.jpg')->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame('delivered', $shoot->fresh()->status);
        $this->assertSame('delivered', $shoot->fresh()->workflow_status);
        $this->assertSame('unpaid', $shoot->fresh()->payment_status);
        $this->assertSame('delivered', $item->fresh()->workflow_status);
        $this->assertSame('delivered', $item->fresh()->delivery_status);
        $this->assertSame($deliveredAt, $item->fresh()->getRawOriginal('delivered_at'));
        $this->assertEquals(26, $item->service->photo_count);
    }

    public function test_cancelled_service_cannot_receive_additional_edits(): void
    {
        [$shoot, $item] = $this->delivered();
        $item->update(['workflow_status' => 'cancelled', 'delivery_status' => 'cancelled', 'cancelled_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post("/api/shoots/{$shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('additional.jpg', 8, 8)],
            'upload_type' => 'edited', 'shoot_service_id' => $item->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(28, $shoot->files()->count());
    }

    public function test_client_cannot_use_additional_edited_uploads(): void
    {
        [$shoot, $item] = $this->delivered();
        $this->actingAs($shoot->client)->post("/api/shoots/{$shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('additional.jpg', 8, 8)],
            'upload_type' => 'edited', 'shoot_service_id' => $item->id,
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(28, $shoot->files()->count());
    }
}
