<?php

namespace Tests\Feature;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\Studio\StudioProviderSettings;
use App\Services\Studio\WorkspaceShootPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootEditingDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'admin', 'metadata' => ['team_id' => 100]]);
        Sanctum::actingAs($this->admin);
        $this->mock(StudioProviderSettings::class, function ($mock) {
            $mock->shouldReceive('route')->andReturnUsing(fn ($preset) => ['provider' => match ($preset) { 'full-shoot' => 'fotello', 'virtual-staging' => 'virtualstagingai', default => 'autoenhance' }, 'model' => 'test']);
            $mock->shouldReceive('readiness')->andReturn(['ready' => true]);
        });
    }

    public function test_partial_selection_uses_autoenhance_but_all_originals_use_one_full_shoot_project(): void
    {
        [$shoot, $files] = $this->shoot();
        $partial = $this->send($shoot, ['file_ids' => [$files[0]->id], 'preset' => 'sky-replacement'])->assertAccepted();
        $partial->assertJsonPath('data.workspaces.0.presetId', 'sky-replacement');
        $full = $this->send($shoot, ['file_ids' => array_reverse(array_column($files, 'id'))])->assertAccepted();
        $full->assertJsonPath('data.workspaces.0.presetId', 'full-shoot');
        $this->assertCount(2, $full->json('data.workspaces.0.media'));
        $this->assertSame('fotello', StudioWorkspace::find($full->json('data.workspaces.0.id'))->operation['routing']['full-shoot']['provider']);
        Queue::assertPushed(ProcessStudioWorkspace::class, 2);
    }

    public function test_addon_targets_are_required_and_linked_under_the_same_shoot(): void
    {
        [$shoot, $files] = $this->shoot(['Photos', 'Virtual Staging', 'Green Grass']);
        $this->send($shoot)->assertUnprocessable()->assertJsonValidationErrors('targets.virtual-staging');
        Queue::assertNothingPushed();
        $data = ['targets' => ['virtual-staging' => [$files[0]->id], 'green-grass' => [$files[1]->id]]];
        $response = $this->send($shoot, $data)->assertAccepted();
        $projects = $response->json('data.workspaces');
        $this->assertCount(3, $projects);
        $this->assertSame($projects[0]['id'], $projects[1]['parentWorkspaceId']);
        $this->assertSame($projects[0]['id'], $projects[2]['parentWorkspaceId']);
        $this->assertSame($shoot->id, $projects[2]['shootId']);
        $this->assertSame($files[1]->id, $projects[2]['media'][0]['fileId']);
        // A retry (even another manager) cannot create another paid intake batch.
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $this->send($shoot, $data)->assertAccepted()->assertJsonCount(3, 'data.workspaces');
        $this->getJson('/api/studio/workspaces/'.$projects[0]['id'])->assertOk();
        Queue::assertPushed(ProcessStudioWorkspace::class, 3);
    }

    public function test_tagged_addons_are_detected_and_foreign_targets_are_rejected(): void
    {
        [$shoot, $files] = $this->shoot(['Photos', 'Green Grass']);
        $files[0]->update(['treatment' => 'green_grass']);
        $this->getJson("/api/shoots/{$shoot->id}/editing-plan")->assertOk()->assertJsonPath('data.addons.0.fileIds', [$files[0]->id]);
        [, $foreign] = $this->shoot();
        $this->send($shoot, ['targets' => ['green-grass' => [$foreign[0]->id]]])->assertUnprocessable();
        $this->send($shoot, ['file_ids' => [$foreign[0]->id]])->assertUnprocessable();
        $files[1]->update(['scan_status' => 'quarantined']);
        $this->send($shoot)->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_ai_mode_assigns_only_video_to_humans_and_manual_mode_assigns_both_lanes(): void
    {
        $photo = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $video = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        [$shoot, $files] = $this->shoot(['Photos', 'Video']);
        $this->send($shoot)->assertAccepted();
        $services = $shoot->fresh('services')->services->keyBy('name');
        $this->assertNull($services['Photos']->pivot->editor_id);
        $this->assertSame($video->id, (int) $services['Video']->pivot->editor_id);
        $this->assertFalse(app(\App\Services\Shoots\ShootEditingAssignmentService::class)->canEditorAccessFile($shoot->fresh(), $files[0], $video));
        [$manual] = $this->shoot(['Photos', 'Video']);
        $this->send($manual, ['mode' => 'editor'])->assertAccepted();
        $services = $manual->fresh('services')->services->keyBy('name');
        $this->assertSame($photo->id, (int) $services['Photos']->pivot->editor_id);
        $this->assertSame($video->id, (int) $services['Video']->pivot->editor_id);
    }

    public function test_missing_video_editor_rolls_back_before_any_ai_work_is_queued(): void
    {
        [$shoot] = $this->shoot(['Photos', 'Video']);
        $this->send($shoot)->assertUnprocessable();
        $this->assertSame('uploaded', $shoot->fresh()->status);
        $this->assertSame(0, StudioWorkspace::count());
        Queue::assertNothingPushed();
    }

    public function test_full_shoot_cannot_be_generated_with_partial_sources_or_frames(): void
    {
        [$shoot, $files] = $this->shoot();
        $workspace = StudioWorkspace::create(['team_id' => 100, 'created_by' => $this->admin->id, 'name' => 'Incomplete', 'preset_id' => 'full-shoot',
            'media' => [['id' => 'one', 'fileId' => $files[0]->id, 'shootId' => $shoot->id]], 'config' => ['frames' => []], 'status' => 'draft']);
        $this->postJson("/api/studio/workspaces/{$workspace->id}/generate")->assertUnprocessable();
        $workspace->update(['media' => [['id' => 'one', 'fileId' => $files[0]->id, 'shootId' => $shoot->id], ['id' => 'two', 'fileId' => $files[1]->id, 'shootId' => $shoot->id]], 'config' => ['frames' => [['mediaId' => 'one']]]]);
        $this->postJson("/api/studio/workspaces/{$workspace->id}/generate")->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_ai_publication_is_idempotent_verified_and_keeps_service_category_and_original(): void
    {
        [$shoot, $files] = $this->shoot(['Photos', 'Green Grass']);
        $response = $this->send($shoot, ['targets' => ['green-grass' => [$files[0]->id]]])->assertAccepted();
        $workspace = StudioWorkspace::findOrFail($response->json('data.workspaces.1.id'));
        Storage::disk('public')->put('result.jpg', 'generated image bytes');
        $publisher = app(WorkspaceShootPublisher::class);
        $output = $publisher->publish($workspace, $workspace->media[0], ['path' => 'result.jpg'], 'output-1');
        $again = $publisher->publish($workspace, $workspace->media[0], ['path' => 'result.jpg'], 'output-1');
        $this->assertSame($output->id, $again->id);
        $this->assertSame('verified', $output->workflow_stage);
        $this->assertSame('green_grass', $output->media_type);
        $this->assertSame($files[0]->shoot_service_id, $output->shoot_service_id);
        $this->assertSame('todo', $files[0]->fresh()->workflow_stage);
        $this->assertTrue($output->is_ai_edited);
    }

    public function test_editors_and_clients_cannot_dispatch_a_shoot(): void
    {
        [$shoot] = $this->shoot();
        foreach (['editor', 'client'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->send($shoot)->assertForbidden();
            $this->getJson("/api/shoots/{$shoot->id}/editing-plan")->assertForbidden();
        }
    }

    public function test_failed_project_resume_retains_paid_provider_checkpoints(): void
    {
        [$shoot] = $this->shoot();
        $response = $this->send($shoot)->assertAccepted();
        $workspace = StudioWorkspace::find($response->json('data.workspaces.0.id'));
        $operation = $workspace->operation;
        $operation['providerState'] = ['photo-listing-'.$shoot->id => ['id' => 'paid-listing']];
        $workspace->update(['status' => 'failed', 'operation' => $operation]);
        $this->patchJson("/api/studio/workspaces/{$workspace->id}", ['config' => $workspace->config, 'version' => $workspace->version])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->postJson("/api/studio/workspaces/{$workspace->id}/generate")->assertAccepted();
        $this->assertSame($operation, $workspace->fresh()->operation);
    }

    public function test_bundled_photo_video_service_routes_and_completes_the_two_lanes_independently(): void
    {
        $photoEditor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo'], 'photo_edit_rate' => 2]]);
        $videoEditor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video'], 'video_edit_rate' => 45]]);
        [$shoot, $files] = $this->shoot(['HDR Photos & Video']);
        $shoot->services()->first()->update(['upload_intake_type' => 'photo_video']);
        $video = $files[0]->replicate();
        $video->fill(['filename' => 'clip.mp4', 'stored_filename' => 'clip.mp4', 'file_type' => 'video/mp4', 'mime_type' => 'video/mp4', 'media_type' => 'video'])->save();
        $this->send($shoot)->assertAccepted();
        $service = $shoot->fresh('services')->services->first();
        $this->assertNull($service->pivot->editor_id);
        $this->assertSame($videoEditor->id, (int) $service->pivot->video_editor_id);
        $assignments = app(\App\Services\Shoots\ShootEditingAssignmentService::class);
        $this->assertTrue($assignments->canEditorAccessFile($shoot->fresh(), $video, $videoEditor));
        $this->assertFalse($assignments->canEditorAccessFile($shoot->fresh(), $files[0], $videoEditor));
        $this->assertSame([$shoot->id], $assignments->scopeAssignedToEditor(Shoot::query(), $videoEditor->id)->pluck('id')->all());
        $project = StudioWorkspace::where('shoot_id', $shoot->id)->first();
        $project->update(['status' => 'completed']);
        app(WorkspaceShootPublisher::class)->completeServices($project);
        $this->assertSame('editing', $shoot->fresh()->status);
        $this->assertFalse($assignments->allTrackedLanesReady($shoot->fresh()));
        $assignments->markAssignedServicesReadyForUser($shoot->fresh(), $videoEditor);
        $this->assertTrue($assignments->allTrackedLanesReady($shoot->fresh()));
        app(WorkspaceShootPublisher::class)->completeServices($project);
        $this->assertSame('ready', $shoot->fresh()->status);

        [$manual] = $this->shoot(['HDR Photos & Video']);
        $manual->services()->first()->update(['upload_intake_type' => 'photo_video']);
        $this->send($manual, ['mode' => 'editor'])->assertAccepted();
        $service = $manual->fresh('services')->services->first();
        $this->assertSame($photoEditor->id, (int) $service->pivot->editor_id);
        $this->assertSame($videoEditor->id, (int) $service->pivot->video_editor_id);
        $assignments->markAssignedServicesReadyForUser($manual->fresh(), $videoEditor);
        $this->assertFalse($assignments->allTrackedLanesReady($manual->fresh()));
        $this->assertNull($manual->fresh('services')->services->first()->pivot->editing_completed_at);
        $manual->services()->first()->update(['photo_count' => 25]);
        $assignments->markAssignedServicesReadyForUser($manual->fresh(), $photoEditor);
        app(\App\Services\EditorPayoutService::class)->syncPayouts();
        $this->assertDatabaseHas('editor_payouts', ['shoot_id' => $manual->id, 'editor_id' => $photoEditor->id, 'payout_amount' => 50]);
        $this->assertDatabaseHas('editor_payouts', ['shoot_id' => $manual->id, 'editor_id' => $videoEditor->id, 'payout_amount' => 45]);
    }

    public function test_ai_only_shoot_becomes_ready_after_every_child_finishes_but_video_keeps_human_review(): void
    {
        [$shoot, $files] = $this->shoot(['Photos', 'Green Grass']);
        $this->send($shoot, ['targets' => ['green-grass' => [$files[0]->id]]])->assertAccepted();
        $projects = StudioWorkspace::where('shoot_id', $shoot->id)->orderBy('created_at')->get();
        $publisher = app(WorkspaceShootPublisher::class);
        $projects[0]->update(['status' => 'completed']);
        $publisher->completeServices($projects[0]);
        $this->assertSame('editing', $shoot->fresh()->status);
        $projects[1]->update(['status' => 'completed']);
        $publisher->completeServices($projects[1]);
        $this->assertSame('ready', $shoot->fresh()->status);
        User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        [$mixed] = $this->shoot(['Photos', 'Video']);
        $this->send($mixed)->assertAccepted();
        $project = StudioWorkspace::where('shoot_id', $mixed->id)->first();
        $project->update(['status' => 'completed']);
        $publisher->completeServices($project);
        $this->assertSame('editing', $mixed->fresh()->status);
        \Illuminate\Support\Facades\DB::table('shoot_service')->where('shoot_id', $mixed->id)->whereNotNull('editor_id')->update(['editing_completed_at' => now()]);
        $publisher->completeServices($project);
        $this->assertSame('ready', $mixed->fresh()->status);
    }

    private function send(Shoot $shoot, array $data = [])
    {
        return $this->postJson("/api/shoots/{$shoot->id}/editing-dispatch", array_replace(['mode' => 'ai', 'request_id' => (string) Str::uuid()], $data));
    }

    private function shoot(array $names = ['Photos']): array
    {
        $shoot = Shoot::factory()->create(['status' => 'uploaded', 'workflow_status' => 'uploaded', 'editor_id' => null]);
        foreach ($names as $name) {
            $service = Service::factory()->create(['name' => $name, 'category_id' => Category::firstOrCreate(['name' => $name])->id]);
            $shoot->services()->attach($service, ['price' => 100, 'quantity' => 1]);
        }
        $pivotId = $shoot->services()->first()->pivot->id;
        $files = [];
        foreach (['a.jpg', 'b.jpg'] as $name) {
            $files[] = ShootFile::create(['shoot_id' => $shoot->id, 'shoot_service_id' => $pivotId, 'filename' => $name, 'stored_filename' => $name, 'file_size' => 100,
                'path' => "shoots/{$shoot->id}/{$name}", 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
                'media_type' => 'photo', 'workflow_stage' => 'todo', 'scan_status' => 'clean', 'uploaded_by' => $this->admin->id]);
        }
        return [$shoot, $files];
    }
}
