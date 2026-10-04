<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootEditingDispatch, ShootEditingDispatchItem, ShootFile, ShootService, User};
use App\Services\ShootMediaStorageService;
use App\Services\Studio\StudioProviderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Queue, Storage};
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Send to editing → editor opens, uploads, sees and submits the shoot through the normal workflow. */
class IntakeEditingAssignmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $editor;
    private Shoot $shoot;
    private ShootService $item;
    /** @var array<int, ShootFile> */
    private array $raws = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->manager = User::factory()->create(['role' => 'superadmin']);
        $this->editor = User::factory()->create(['role' => 'editor', 'name' => 'Photo editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        // Production shape (shoots 641/668/670): uploaded HDR brackets, service line without an editor.
        $this->shoot = Shoot::factory()->create(['status' => 'uploaded', 'workflow_status' => 'uploaded', 'editor_id' => null]);
        $this->item = ShootService::create(['shoot_id' => $this->shoot->id, 'service_id' => Service::factory()->create(['name' => '25 HDR Photos', 'upload_intake_type' => 'photo', 'requires_editing' => true])->id,
            'quantity' => 1, 'price' => 100]);
        foreach ([1, 1, 2, 2] as $index => $bracket) {
            $this->raws[] = ShootFile::create(['shoot_id' => $this->shoot->id, 'shoot_service_id' => $this->item->id, 'filename' => "raw{$index}.jpg", 'stored_filename' => "raw{$index}.jpg",
                'path' => "shoots/{$this->shoot->id}/raw/raw{$index}.jpg", 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 100,
                'media_type' => 'raw', 'workflow_stage' => 'todo', 'bracket_group' => $bracket, 'scan_status' => 'clean', 'uploaded_by' => $this->manager->id])->fresh();
        }
        $this->mock(StudioProviderSettings::class, function ($mock) {
            $mock->shouldReceive('route')->andReturn(['provider' => 'fotello', 'model' => 'test']);
            $mock->shouldReceive('readiness')->andReturn(['ready' => true]);
        });
    }

    private function sendToEditor(array $changes = [])
    {
        return $this->actingAs($this->manager)->postJson("/api/shoots/{$this->shoot->id}/editing-dispatch", array_replace([
            'mode' => 'editor', 'scope' => 'whole', 'preset' => 'full-shoot', 'request_id' => (string) Str::uuid(),
            'source_versions' => collect($this->raws)->mapWithKeys(fn ($file) => [$file->id => $file->content_version])->all(),
            'photo_editor_id' => null, 'video_editor_id' => null,
        ], $changes));
    }

    private function fakeEditedStorage(): void
    {
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('uploadToCompleted')->andReturnUsing(
            function (Shoot $shoot, UploadedFile $file, int $actor, mixed $category, mixed $type, ?int $scope) {
                $path = "shoots/{$shoot->id}/completed/".$file->getClientOriginalName();
                Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
                return ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $scope, 'filename' => $file->getClientOriginalName(),
                    'stored_filename' => $file->getClientOriginalName(), 'path' => $path, 'file_type' => 'image/jpeg', 'file_size' => $file->getSize(),
                    'media_type' => 'edited', 'workflow_stage' => 'completed', 'scan_status' => 'clean', 'uploaded_by' => $actor]);
            }
        );
        app()->instance(ShootMediaStorageService::class, $storage);
    }

    private function assertEditorCompletesShootNormally(User $editor): void
    {
        $this->actingAs($editor)->getJson("/api/shoots/{$this->shoot->id}")->assertOk();
        $this->assertContains($this->shoot->id, collect($this->getJson('/api/shoots?tab=completed&dashboard_open=1&no_cache=true&include_files=false')->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->getJson("/api/shoots/{$this->shoot->id}/files?type=raw")->assertOk()->assertJsonCount(count($this->raws), 'data');
        $this->fakeEditedStorage();
        $this->post("/api/shoots/{$this->shoot->id}/upload", [
            'files' => [UploadedFile::fake()->image('living-room.jpg', 8, 8)], 'upload_type' => 'edited', 'shoot_service_id' => $this->item->id,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 1);
        $edited = collect($this->getJson("/api/shoots/{$this->shoot->id}/files?type=edited")->assertOk()->json('data'))->pluck('filename');
        $this->assertContains('living-room.jpg', $edited->all());
        $this->postJson("/api/shoots/{$this->shoot->id}/upload/finalize-edited")->assertOk()->assertJsonPath('shoot_status', 'review');
        $this->assertNotNull($this->item->fresh()->editing_completed_at);
    }

    public function test_whole_shoot_to_human_editor_assigns_the_lane_and_completes_through_the_normal_workflow(): void
    {
        $this->sendToEditor()->assertAccepted()->assertJsonPath('data.mode', 'editor');
        $this->assertSame($this->editor->id, (int) $this->item->fresh()->editor_id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->assertSame(0, ShootEditingDispatchItem::count());
        $this->assertEditorCompletesShootNormally($this->editor);
    }

    public function test_editor_override_becomes_the_lane_assignment_and_resending_reassigns(): void
    {
        $other = User::factory()->create(['role' => 'editor', 'name' => 'Another editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $this->sendToEditor(['photo_editor_id' => $other->id])->assertAccepted();
        $this->assertSame($other->id, (int) $this->item->fresh()->editor_id);
        $this->sendToEditor(['photo_editor_id' => $this->editor->id])->assertAccepted();
        $this->assertSame($this->editor->id, (int) $this->item->fresh()->editor_id);
        $this->actingAs($other)->getJson("/api/shoots/{$this->shoot->id}")->assertForbidden();
        $this->sendToEditor(['photo_editor_id' => User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]])->id])->assertUnprocessable();
        $this->assertSame($this->editor->id, (int) $this->item->fresh()->editor_id);
    }

    public function test_selected_raws_to_an_editor_assign_the_lane_and_leave_a_note_naming_them(): void
    {
        $this->sendToEditor(['scope' => 'selected', 'preset' => 'revision', 'file_ids' => [$this->raws[0]->id], 'instructions' => 'Kitchen first'])->assertAccepted();
        $this->assertSame($this->editor->id, (int) $this->item->fresh()->editor_id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->assertSame(0, ShootEditingDispatchItem::count());
        $note = \App\Models\ShootNote::where('shoot_id', $this->shoot->id)->sole();
        $this->assertSame('editing', $note->type);
        $this->assertStringContainsString('Edit these 2 files: raw0.jpg, raw1.jpg', $note->content);
        $this->assertStringContainsString('Kitchen first', $note->content);
        $this->getJson("/api/shoots/{$this->shoot->id}/editing-plan")->assertOk()->assertJsonPath('data.lanes.photo.sent', false);
        $this->assertEditorCompletesShootNormally($this->editor);
    }

    public function test_selected_media_to_a_human_editor_remains_a_scoped_task_without_shoot_assignment(): void
    {
        $this->shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $this->sendToEditor(['scope' => 'selected', 'preset' => 'revision', 'file_ids' => [$this->raws[0]->id], 'instructions' => 'Brighten'])->assertAccepted();
        $this->assertNull($this->item->fresh()->editor_id);
        $this->assertSame(1, ShootEditingDispatchItem::where('editor_id', $this->editor->id)->where('status', 'assigned')->count());
    }

    public function test_migration_repairs_intake_tasks_created_without_a_lane_assignment(): void
    {
        $this->shoot->update(['status' => 'editing', 'workflow_status' => 'editing']);
        $dispatch = ShootEditingDispatch::create(['shoot_id' => $this->shoot->id, 'created_by' => $this->manager->id, 'request_id' => (string) Str::uuid(),
            'scope' => 'whole', 'destination' => 'human', 'workflow' => 'full-shoot', 'instructions' => '', 'input_hash' => 'x', 'plan' => [], 'status' => 'assigned']);
        foreach ([1, 2] as $bracket) {
            $dispatch->items()->create(['input_key' => "full-shoot:stack:{$bracket}", 'workflow' => 'full-shoot', 'sources' => [], 'shoot_service_id' => $this->item->id,
                'lane' => 'photo', 'destination' => 'human', 'editor_id' => $this->editor->id, 'status' => 'assigned']);
        }
        $this->actingAs($this->editor)->postJson("/api/shoots/{$this->shoot->id}/upload/finalize-edited")->assertForbidden();

        (require database_path('migrations/2026_10_04_120000_assign_intake_editing_dispatch_lanes.php'))->up();

        $this->assertSame($this->editor->id, (int) $this->item->fresh()->editor_id);
        $this->assertSame(2, ShootEditingDispatchItem::where('status', 'cancelled')->count());
        $this->getJson('/api/editing-tasks?open=true&summary=true')->assertOk()->assertJsonCount(0, 'data');
        $this->assertEditorCompletesShootNormally($this->editor);
    }
}
