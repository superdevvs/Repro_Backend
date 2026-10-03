<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Studio\WorkspaceServiceScope;
use App\Services\Studio\WorkspaceShootPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiUnitStudioIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $service = Service::factory()->create(['name' => 'Photos', 'category_id' => Category::factory()->create(['name' => 'Photos'])->id, 'upload_intake_type' => 'photo', 'requires_editing' => true]);
        $lines = collect([1, 2])->map(function ($id) use ($shoot, $service) {
            $unit = $shoot->units()->create(['client_key' => "u$id", 'label' => "Unit $id", 'kind' => 'unit', 'sqft' => 1000]);
            return ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id, 'shoot_unit_id' => $unit->id, 'client_key' => "l$id", 'price' => 200, 'quantity' => 1]);
        });
        return [$shoot, $service, $lines];
    }

    public function test_editor_completion_and_auto_assignment_do_not_touch_a_sibling_unit(): void
    {
        [$shoot, , $lines] = $this->fixture();
        $editor = User::factory()->create(['role' => 'editor']);
        $peer = User::factory()->create(['role' => 'editor']);
        $lines[0]->update(['editor_id' => $editor->id]);
        $lines[1]->update(['editor_id' => $peer->id]);
        ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $lines[0]->id, 'filename' => 'unit-1-edited.jpg',
            'stored_filename' => 'unit-1-edited.jpg', 'path' => 'edited/unit-1.jpg', 'file_type' => 'image/jpeg', 'file_size' => 100,
            'workflow_stage' => 'completed', 'media_type' => 'edited', 'uploaded_by' => $editor->id]);
        $assignments = app(ShootEditingAssignmentService::class);
        $assignments->markAssignedServicesReadyForUser($shoot, $editor);
        $this->assertNotNull($lines[0]->fresh()->editing_completed_at);
        $this->assertNull($lines[1]->fresh()->editing_completed_at);
        $payload = $assignments->buildEditorAssignmentsPayload($shoot->fresh(), $editor);
        $this->assertCount(1, $payload);
        $this->assertSame([(string) $lines[0]->id], $payload[0]['shoot_service_ids']);
        $lines[0]->update(['editor_id' => null]);
        $assignments->autoAssignEditorsForShoot($shoot->fresh());
        $this->assertSame($peer->id, (int) $lines[1]->fresh()->editor_id);
    }

    public function test_studio_completion_uses_execution_ids_and_legacy_catalog_ids_cannot_match_unit_lines(): void
    {
        [$shoot, $service, $lines] = $this->fixture();
        $workspace = StudioWorkspace::create(['team_id' => 1, 'created_by' => User::factory()->create()->id, 'shoot_id' => $shoot->id, 'name' => 'Unit 1', 'preset_id' => 'listing-ready', 'media' => [], 'config' => [], 'status' => 'completed', 'shoot_dispatch_key' => 'test', 'shoot_service_ids' => [$service->id], 'shoot_service_item_ids' => [$lines[0]->id]]);
        app(WorkspaceShootPublisher::class)->completeServices($workspace);
        $this->assertNotNull($lines[0]->fresh()->editing_completed_at);
        $this->assertNull($lines[1]->fresh()->editing_completed_at);
        $workspace->shoot_service_item_ids = null;
        $this->assertSame([], WorkspaceServiceScope::lineIds($workspace));
    }

    public function test_studio_picker_exposes_unit_identity_and_only_assigned_unit_files(): void
    {
        [$shoot, , $lines] = $this->fixture();
        $editor = User::factory()->create(['role' => 'editor']);
        $lines[0]->update(['editor_id' => $editor->id]);
        $lines[1]->update(['editor_id' => User::factory()->create(['role' => 'editor'])->id]);
        $files = $lines->map(fn ($line) => ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $line->id, 'filename' => "unit-{$line->id}.jpg", 'stored_filename' => "unit-{$line->id}.jpg", 'path' => 'fixture.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg', 'file_size' => 10, 'uploaded_by' => $editor->id, 'media_type' => 'raw', 'workflow_stage' => 'todo', 'scan_status' => ShootFile::SCAN_STATUS_CLEAN, 'is_hidden' => false]));
        Sanctum::actingAs($editor);
        $this->getJson("/api/studio/workspaces/sources/shoots/{$shoot->id}/media?workflow=photo-enhancement")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $files[0]->id)->assertJsonPath('data.0.shootUnitId', $lines[0]->shoot_unit_id)->assertJsonPath('data.0.unitLabel', 'Unit 1');
        $visible = app(ShootEditingAssignmentService::class)->filterFilesForEditor($files, $shoot, $editor);
        $this->assertSame([$files[0]->id], $visible->pluck('id')->all());
    }
}
