<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootFile, ShootService, ShootUploadAttempt, User};
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\{ShootAuthorizationSupport, ShootEditingAssignmentService, ShootServiceItemSupport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue, Storage};
use Mockery;
use Tests\TestCase;

class OptionalEditingUploadAccessTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $scoped = false, bool $required = true): array
    {
        Queue::fake();
        Storage::fake('public');
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['status' => 'editing', 'workflow_status' => 'editing', 'editor_id' => $editor->id]);
        $service = Service::factory()->unbracketedPhoto()->create(['name' => 'Drone photos', 'requires_editing' => false]);
        $item = ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'editor_id' => $editor->id, 'quantity' => 1, 'price' => 100]);
        ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $scoped ? $item->id : null,
            'filename' => 'capture.jpg', 'stored_filename' => 'capture.jpg', 'path' => 'capture.jpg',
            'file_type' => 'image/jpeg', 'file_size' => 100, 'media_type' => 'raw', 'workflow_stage' => 'todo',
            'is_extra' => false, 'required_for_editing' => $required, 'uploaded_by' => $editor->id]);
        return [$shoot, $item, $editor];
    }

    private function storage(): void
    {
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToCompleted')->once()->andReturnUsing(
            function (Shoot $shoot, UploadedFile $file, int $actor, mixed $category, mixed $type, ?int $scope) {
                $path = "shoots/{$shoot->id}/completed/".$file->getClientOriginalName();
                Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
                return ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $scope,
                    'filename' => $file->getClientOriginalName(), 'stored_filename' => $file->getClientOriginalName(),
                    'path' => $path, 'file_type' => 'image/jpeg', 'file_size' => $file->getSize(),
                    'media_type' => 'edited', 'workflow_stage' => 'completed', 'uploaded_by' => $actor]);
            }
        );
        app()->instance(ShootMediaStorageService::class, $storage);
    }

    public function test_assigned_editor_can_upload_legacy_unscoped_required_drone_intake(): void
    {
        [$shoot, $item, $editor] = $this->fixture();
        $this->storage();
        $this->actingAs($editor)->post("/api/shoots/{$shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('edited.jpg', 8, 8)], 'upload_type' => 'edited',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 1)
            ->assertJsonPath('uploaded_files.0.shoot_service_id', $item->id);
        $this->assertFalse($item->service->fresh()->requires_editing);
        $summary = app(ShootServiceItemSupport::class)->summaries($shoot->fresh());
        $this->assertTrue($summary[0]['requires_editing']);
    }

    public function test_scoped_required_optional_intake_is_allowed_by_both_upload_authorization_paths(): void
    {
        [$shoot, $item, $editor] = $this->fixture(scoped: true);
        $this->assertTrue(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $editor, 'edited', $item->id));
        $this->storage();
        $this->actingAs($editor)->post("/api/shoots/{$shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('edited.jpg', 8, 8)], 'upload_type' => 'edited', 'shoot_service_id' => $item->id,
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_catalog_optional_service_without_required_intake_remains_hidden_and_forbidden(): void
    {
        [$shoot, $item, $editor] = $this->fixture(required: false);
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $editor, 'edited', $item->id));
        $this->assertTrue(app(ShootEditingAssignmentService::class)->filterServicesForEditor($shoot, $editor)->isEmpty());
        $this->actingAs($editor)->post("/api/shoots/{$shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('edited.jpg', 8, 8)], 'upload_type' => 'edited',
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_legacy_unscoped_intake_is_not_guessed_between_two_photo_services(): void
    {
        [$shoot, $item, $editor] = $this->fixture();
        ShootService::create(['shoot_id' => $shoot->id, 'service_id' => Service::factory()->unbracketedPhoto()->create(['requires_editing' => false])->id,
            'editor_id' => $editor->id, 'quantity' => 1, 'price' => 100]);
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $editor, 'edited', $item->id));
    }

    public function test_another_editor_cannot_upload_to_the_optional_assignment(): void
    {
        [$shoot, $item] = $this->fixture();
        $other = User::factory()->create(['role' => 'editor']);
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canUploadShootMedia($shoot, $other, 'edited', $item->id));
        $this->actingAs($other)->post("/api/shoots/{$shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('edited.jpg', 8, 8)], 'upload_type' => 'edited', 'shoot_service_id' => $item->id,
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_permission_rejection_retries_with_the_same_identity_after_required_intake_is_restored(): void
    {
        [$shoot, $item, $editor] = $this->fixture(required: false);
        $file = UploadedFile::fake()->image('retry.jpg', 8, 8);
        $payload = ['files' => [$file], 'upload_type' => 'edited', 'idempotency_key' => 'same-editor-retry'];
        $this->storage();
        $this->actingAs($editor)->post("/api/shoots/{$shoot->id}/upload", $payload, ['Accept' => 'application/json'])->assertForbidden();
        $attemptId = ShootUploadAttempt::sole()->id;
        ShootFile::where('shoot_id', $shoot->id)->update(['required_for_editing' => true]);
        $success = $this->post("/api/shoots/{$shoot->id}/upload", $payload, ['Accept' => 'application/json'])->assertOk();
        $this->post("/api/shoots/{$shoot->id}/upload", $payload, ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('uploaded_files.0.id', $success->json('uploaded_files.0.id'));
        $this->assertSame($attemptId, ShootUploadAttempt::sole()->id);
        $this->assertSame(1, ShootFile::where('shoot_id', $shoot->id)->where('workflow_stage', 'completed')->count());
    }
}
