<?php

namespace Tests\Feature;

use App\Jobs\PrepareEditingDispatch;
use App\Jobs\ProcessMediaVersion;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\{Category, Service, Shoot, ShootEditingDispatch, ShootEditingDispatchItem, ShootFile, ShootFileVersion, StudioWorkspace, User};
use App\Services\Media\MediaStorage;
use App\Services\Scanning\{ClamAvClient, ClamAvScanResult};
use App\Services\Studio\StudioProviderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class ScopedEditingDispatchTest extends TestCase
{
    use RefreshDatabase;
    private User $manager;
    private User $photoEditor;
    private User $videoEditor;
    private Shoot $shoot;
    private ShootFile $photo;
    private ShootFile $video;

    #[\PHPUnit\Framework\Attributes\DataProvider('externalStaffRoles')]
    public function test_staff_can_send_external_video_without_dashboard_media(string $role): void
    {
        $this->manager->update(['role' => $role]);
        $this->photo->delete();
        $this->video->delete();
        $this->shoot->update(['status' => 'scheduled', 'workflow_status' => 'scheduled', 'photos_uploaded_at' => null]);
        DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->update(['editor_id' => null, 'video_editor_id' => null]);
        $this->getJson('/api/shoots/'.$this->shoot->id.'/editing-plan')->assertOk()
            ->assertJsonPath('data.lanes.video.available', true)->assertJsonPath('data.lanes.video.external', true)
            ->assertJsonPath('data.lanes.video.sent', false);
        $data = ['mode' => 'editor', 'scope' => 'videos', 'source_versions' => [], 'request_id' => (string) Str::uuid()];
        $id = $this->send($data)->assertAccepted()->json('data.dispatchId');
        $this->send($data)->assertAccepted()->assertJsonPath('data.dispatchId', $id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->assertNull($this->shoot->fresh()->photos_uploaded_at);
        $service = DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->first();
        $this->assertSame($this->videoEditor->id, $service->video_editor_id);
        $this->assertNull($service->editor_id);
        $this->assertSame(0, $this->shoot->files()->count());
        $this->assertSame(0, StudioWorkspace::count());
        $this->assertSame('external', ShootEditingDispatch::findOrFail($id)->plan['items'][0]['source']);
        $this->assertTrue(app(\App\Services\Shoots\ShootAuthorizationSupport::class)->canEditVideoTourLinks($this->shoot->fresh(), $this->videoEditor));
        $this->assertTrue(app(\App\Services\Shoots\ShootAuthorizationSupport::class)->canUploadShootMedia($this->shoot->fresh(), $this->videoEditor, 'edited', $service->id));
        $this->assertTrue(app(\App\Services\Shoots\ShootAuthorizationSupport::class)->scopeAccessibleShootMedia(Shoot::query(), $this->videoEditor)->where('shoots.id', $this->shoot->id)->exists());
        $this->getJson('/api/shoots/'.$this->shoot->id.'/editing-plan')->assertOk()->assertJsonPath('data.lanes.video.sent', true);
        $this->actingAs($this->videoEditor)->getJson('/api/shoots?tab=completed&dashboard_open=true&no_cache=true')
            ->assertOk()->assertJsonPath('data.0.id', $this->shoot->id);
    }

    public static function externalStaffRoles(): array
    {
        return [['admin'], ['superadmin'], ['editing_manager']];
    }

    public function test_external_dispatch_still_requires_booked_service_and_rejects_ai_without_photos(): void
    {
        $this->photo->delete();
        $this->video->delete();
        $this->send(['mode' => 'ai', 'scope' => 'whole', 'source_versions' => [], 'request_id' => (string) Str::uuid()])->assertStatus(422);
        $this->shoot->services()->detach();
        $this->send(['mode' => 'editor', 'scope' => 'videos', 'source_versions' => [], 'request_id' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_whole_shoot_external_handoff_assigns_both_booked_lanes(): void
    {
        $this->photo->delete();
        $this->video->delete();
        $this->shoot->update(['status' => 'scheduled', 'workflow_status' => 'scheduled']);
        DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->update(['editor_id' => null, 'video_editor_id' => null]);
        $this->send(['mode' => 'editor', 'scope' => 'whole', 'source_versions' => [], 'request_id' => (string) Str::uuid()])->assertAccepted();
        $item = DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->first();
        $this->assertSame($this->photoEditor->id, $item->editor_id);
        $this->assertSame($this->videoEditor->id, $item->video_editor_id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
    }

    public function test_ai_photos_can_be_sent_with_externally_shared_video_for_the_human_editor(): void
    {
        $this->video->delete();
        DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->update(['video_editor_id' => null]);
        $data = $this->payload(['mode' => 'ai', 'scope' => 'whole', 'preset' => 'full-shoot']);
        unset($data['file_ids']);
        $id = $this->send($data)->assertAccepted()->json('data.dispatchId');
        $this->assertSame($this->videoEditor->id, DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->value('video_editor_id'));
        $this->assertSame(1, ShootEditingDispatchItem::where('dispatch_id', $id)->where('destination', 'ai')->count());
        $this->assertSame(0, ShootEditingDispatchItem::where('dispatch_id', $id)->where('destination', 'human')->count());
        $this->assertSame('external', collect(ShootEditingDispatch::find($id)->plan['items'])->firstWhere('lane', 'video')['source']);
    }

    public function test_external_dispatch_does_not_reopen_cancelled_or_delivered_shoots(): void
    {
        $this->photo->delete();
        $this->video->delete();
        foreach (['cancelled', 'on_hold', 'delivered'] as $status) {
            $this->shoot->update(['status' => $status, 'workflow_status' => $status]);
            $this->send(['mode' => 'editor', 'scope' => 'videos', 'source_versions' => [], 'request_id' => (string) Str::uuid()])->assertStatus(422);
            $this->assertSame($status, $this->shoot->fresh()->workflow_status);
        }
    }

    public function test_editing_manager_can_send_requested_external_video_to_the_normal_editor_queue(): void
    {
        $this->photo->delete();
        $this->video->delete();
        $this->shoot->update(['status' => 'requested', 'workflow_status' => 'requested', 'photos_uploaded_at' => null]);
        DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->update(['editor_id' => null, 'video_editor_id' => null]);
        $this->getJson('/api/shoots/'.$this->shoot->id.'/editing-plan')->assertOk()
            ->assertJsonPath('data.lanes.video.available', true)->assertJsonPath('data.lanes.video.sent', false);
        $data = ['mode' => 'editor', 'scope' => 'videos', 'source_versions' => [], 'request_id' => (string) Str::uuid()];
        $id = $this->send($data)->assertAccepted()->json('data.dispatchId');
        $this->send($data)->assertAccepted()->assertJsonPath('data.dispatchId', $id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->assertNull($this->shoot->fresh()->photos_uploaded_at);
        $service = DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->first();
        $this->assertSame($this->videoEditor->id, $service->video_editor_id);
        $this->assertNull($service->editor_id);
        $this->assertSame(0, $this->shoot->files()->count());
        $this->assertSame(0, ShootEditingDispatchItem::where('dispatch_id', $id)->count());
        $this->actingAs($this->videoEditor)->getJson('/api/shoots?tab=completed&dashboard_open=true&no_cache=true')
            ->assertOk()->assertJsonPath('data.0.id', $this->shoot->id);
    }

    public function test_requested_external_handoff_is_not_enabled_for_other_staff_roles(): void
    {
        $this->photo->delete();
        $this->video->delete();
        $this->shoot->update(['status' => 'requested', 'workflow_status' => 'requested']);
        foreach (['admin', 'superadmin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->send(['mode' => 'editor', 'scope' => 'videos', 'source_versions' => [], 'request_id' => (string) Str::uuid()])->assertStatus(422);
            $this->assertSame('requested', $this->shoot->fresh()->workflow_status);
        }
    }

    public function test_requested_whole_shoot_handoff_assigns_both_external_lanes(): void
    {
        $this->photo->delete();
        $this->video->delete();
        $this->shoot->update(['status' => 'requested', 'workflow_status' => 'requested', 'photos_uploaded_at' => null]);
        DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->update(['editor_id' => null, 'video_editor_id' => null]);
        $this->send(['mode' => 'editor', 'scope' => 'whole', 'source_versions' => [], 'request_id' => (string) Str::uuid()])->assertAccepted();
        $service = DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->first();
        $this->assertSame($this->photoEditor->id, $service->editor_id);
        $this->assertSame($this->videoEditor->id, $service->video_editor_id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->assertNull($this->shoot->fresh()->photos_uploaded_at);
    }

    public function test_requested_handoff_with_dashboard_sources_uses_normal_intake_instead_of_revision_tasks(): void
    {
        $this->shoot->update(['status' => 'requested', 'workflow_status' => 'requested', 'photos_uploaded_at' => null]);
        $id = $this->send($this->payload(['mode' => 'editor', 'scope' => 'videos']))->assertAccepted()->json('data.dispatchId');
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->assertSame(0, ShootEditingDispatchItem::where('dispatch_id', $id)->count());
        $this->assertNull($this->shoot->fresh()->photos_uploaded_at);
    }

    public function test_non_staff_cannot_send_external_media_to_editors(): void
    {
        foreach (['editor', 'photographer', 'client', 'salesrep'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->send(['mode' => 'editor', 'scope' => 'videos', 'source_versions' => [], 'request_id' => (string) Str::uuid()])->assertForbidden();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.local_disk' => 'local', 'media.tiered_storage_enabled' => false, 'media.dual_write' => false, 'media.r2_only' => false, 'media.read_from_r2' => false]);
        $this->manager = User::factory()->create(['role' => 'editing_manager']);
        $this->photoEditor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $this->videoEditor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['video']]]);
        $this->shoot = Shoot::factory()->create(['status' => 'uploaded', 'workflow_status' => 'uploaded', 'editor_id' => null]);
        $service = Service::factory()->create(['name' => 'Photos and Video', 'upload_intake_type' => 'photo_video', 'category_id' => Category::firstOrCreate(['name' => 'Photos'])->id]);
        $this->shoot->services()->attach($service, ['price' => 100, 'quantity' => 1, 'editor_id' => $this->photoEditor->id, 'video_editor_id' => $this->videoEditor->id]);
        $this->photo = ShootFile::create(['shoot_id' => $this->shoot->id, 'shoot_service_id' => $this->shoot->services()->first()->pivot->id,
            'filename' => 'kitchen.jpg', 'stored_filename' => 'kitchen.jpg', 'path' => 'shoots/test/kitchen.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 100, 'media_type' => 'photo', 'workflow_stage' => 'todo', 'scan_status' => 'clean', 'uploaded_by' => $this->manager->id])->fresh();
        $this->video = $this->photo->replicate();
        $this->video->fill(['filename' => 'video.mp4', 'stored_filename' => 'video.mp4', 'file_type' => 'video/mp4', 'mime_type' => 'video/mp4', 'media_type' => 'video'])->save();
        $this->video->refresh();
        $this->mock(StudioProviderSettings::class, function ($mock) {
            $mock->shouldReceive('route')->andReturnUsing(fn ($preset) => ['provider' => $preset === 'full-shoot' ? 'fotello' : 'autoenhance', 'model' => 'test']);
            $mock->shouldReceive('readiness')->andReturn(['ready' => true]);
        });
        $this->mock(ClamAvClient::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn(ClamAvScanResult::clean()));
        $this->actingAs($this->manager);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['mode' => 'ai', 'scope' => 'selected', 'preset' => 'listing-ready', 'file_ids' => [$this->photo->id],
            'source_versions' => [$this->photo->id => 1, $this->video->id => 1], 'request_id' => (string) Str::uuid()], $changes);
    }

    private function send(array $data)
    {
        return $this->postJson("/api/shoots/{$this->shoot->id}/editing-dispatch", $data);
    }

    public function test_explicit_mixed_scope_routes_video_to_existing_editor_and_deduplicates_request_and_worker(): void
    {
        $data = $this->payload(['scope' => 'whole', 'preset' => 'full-shoot']);
        unset($data['file_ids']);
        $preview = $this->postJson("/api/shoots/{$this->shoot->id}/editing-plan", $data)->assertOk();
        $preview->assertJsonPath('data.photoCount', 1)->assertJsonPath('data.videoCount', 1);
        $id = $this->send($data)->assertAccepted()->json('data.dispatchId');
        $this->send($data)->assertAccepted()->assertJsonPath('data.dispatchId', $id);
        $this->assertDatabaseCount('shoot_editing_dispatch_items', 1);
        Queue::assertPushed(PrepareEditingDispatch::class, 1);
        $this->assertDatabaseMissing('shoot_editing_dispatch_items', ['dispatch_id' => $id, 'destination' => 'human']);
        $this->assertSame($this->videoEditor->id, (int) DB::table('shoot_service')->first()->video_editor_id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        app()->call([new PrepareEditingDispatch($id), 'handle']);
        app()->call([new PrepareEditingDispatch($id), 'handle']);
        $this->assertDatabaseCount('studio_workspaces', 1);
        Queue::assertPushed(ProcessStudioWorkspace::class, 1);
        $this->assertSame('fotello', StudioWorkspace::first()->operation['routing']['full-shoot']['provider']);
        $this->assertSame($this->photoEditor->id, (int) DB::table('shoot_service')->first()->editor_id);
        $this->send(array_replace($data, ['instructions' => 'different']))->assertConflict();
    }

    public function test_all_selected_photos_stay_selected_and_delivered_shoot_does_not_reopen(): void
    {
        $this->shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $this->photo->update(['workflow_stage' => 'verified', 'media_type' => 'edited']);
        $id = $this->send($this->payload())->assertAccepted()->json('data.dispatchId');
        $this->assertSame('selected', ShootEditingDispatch::find($id)->scope);
        app()->call([new PrepareEditingDispatch($id), 'handle']);
        $this->assertSame('listing-ready', StudioWorkspace::first()->preset_id);
        $this->assertSame('delivered', $this->shoot->fresh()->workflow_status);
        $this->assertDatabaseCount('shoot_editing_dispatch_items', 1);
    }

    public function test_dashboard_queue_includes_request_assignments_without_granting_shoot_wide_access(): void
    {
        $this->shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered', 'scheduled_at' => '2026-10-03 22:00:00', 'timezone' => 'America/New_York',
            'address' => 'Test Street', 'property_details' => ['aptSuite' => '93', 'lockbox_code' => 'secret'],
            'company_notes' => 'private', 'total_quote' => 999]);
        $this->shoot->photographer?->update(['timezone' => 'America/New_York']);
        $override = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $dispatchId = $this->send($this->payload(['mode' => 'editor', 'photo_editor_id' => $override->id]))->assertAccepted()->json('data.dispatchId');
        $item = ShootEditingDispatchItem::where('dispatch_id', $dispatchId)->firstOrFail();
        $this->actingAs($override)->getJson('/api/editing-tasks?open=true&summary=true')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.shoot_id', $this->shoot->id)
            ->assertJsonPath('data.0.pending_items_count', 1)->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.plan')
            ->assertJsonPath('data.0.shoot.scheduledLocalDate', '2026-10-03')
            ->assertJsonPath('data.0.shoot.timeLabel', '6:00 PM')
            ->assertJsonPath('data.0.shoot.scheduleTimezone', 'America/New_York')
            ->assertJsonPath('data.0.shoot.services.0.label', 'Photos and Video')
            ->assertJsonPath('data.0.shoot.hasScopedEditingTasks', true)
            ->assertJsonMissingPath('data.0.shoot.company_notes')
            ->assertJsonMissingPath('data.0.shoot.total_quote')
            ->assertJsonMissingPath('data.0.shoot.property_details')
            ->assertJsonMissingPath('data.0.shoot.files');
        $this->assertSame($this->photoEditor->id, (int) DB::table('shoot_service')->first()->editor_id);
        $this->getJson("/api/editing-tasks/{$item->id}/sources/{$this->video->id}")->assertNotFound();
        $this->actingAs($this->videoEditor)->getJson('/api/editing-tasks?open=true&summary=true')->assertOk()->assertJsonCount(0, 'data');
        $item->update(['status' => 'completed']);
        $this->actingAs($override)->getJson('/api/editing-tasks?open=true&summary=true')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/editing-tasks?shoot_id='.$this->shoot->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_dashboard_queue_excludes_cancelled_shoots_and_non_editor_roles(): void
    {
        $this->send($this->payload(['mode' => 'editor']))->assertAccepted();
        $this->shoot->update(['status' => 'cancelled', 'workflow_status' => 'cancelled']);
        $this->actingAs($this->photoEditor)->getJson('/api/editing-tasks?open=true&summary=true')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create(['role' => 'client']))->getJson('/api/editing-tasks?open=true&summary=true')->assertForbidden();
    }

    public function test_video_ai_and_invalid_versions_and_ineligible_override_are_rejected_without_enqueues(): void
    {
        $this->send($this->payload(['scope' => 'videos']))->assertUnprocessable();
        $this->send($this->payload(['file_ids' => [$this->video->id]]))->assertUnprocessable();
        $this->send($this->payload(['source_versions' => [$this->photo->id => 2]]))->assertConflict();
        $this->send($this->payload(['mode' => 'editor', 'photo_editor_id' => $this->videoEditor->id]))->assertUnprocessable();
        $this->send($this->payload(['preset' => 'full-shoot']))->assertUnprocessable();
        $this->assertDatabaseCount('shoot_editing_dispatches', 0);
        Queue::assertNothingPushed();
    }

    public function test_hdr_preview_expands_all_exposures_and_rejects_stale_hidden_and_deleted_sources(): void
    {
        $this->photo->update(['bracket_group' => 1]);
        $other = $this->photo->replicate();
        $other->fill(['filename' => 'exposure2.jpg'])->save();
        $data = $this->payload(['source_versions' => [$this->photo->id => 1, $other->id => 1]]);
        $this->postJson("/api/shoots/{$this->shoot->id}/editing-plan", $data)->assertOk()->assertJsonPath('data.inputCount', 1)->assertJsonPath('data.sourceCount', 2);
        $other->update(['is_hidden' => true]);
        $this->send($data)->assertUnprocessable();
        $other->update(['is_hidden' => false, 'content_version' => 2]);
        $this->send($data)->assertConflict();
        $this->photo->delete();
        $this->send($data)->assertUnprocessable();
    }

    public function test_request_override_has_exact_source_access_and_return_submission_does_not_complete_other_work(): void
    {
        $this->shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $override = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo']]]);
        $data = $this->payload(['mode' => 'editor', 'photo_editor_id' => $override->id, 'instructions' => 'Green grass only']);
        $this->send($data)->assertAccepted();
        $item = ShootEditingDispatchItem::firstOrFail();
        $this->assertSame($this->photoEditor->id, (int) DB::table('shoot_service')->first()->editor_id);
        $this->actingAs($this->videoEditor)->getJson('/api/editing-tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/editing-tasks/{$item->id}/submit")->assertForbidden();
        $this->actingAs($override)->getJson('/api/editing-tasks')->assertOk()->assertJsonCount(1, 'data.0.items')->assertJsonMissingPath('data.0.plan');
        $this->getJson("/api/editing-tasks/{$item->id}/sources/{$this->video->id}")->assertNotFound();
        $this->postJson("/api/editing-tasks/{$item->id}/submit")->assertConflict();
        $upload = UploadedFile::fake()->image('edited.jpg', 48, 32);
        $returned = $this->postJson("/api/editing-tasks/{$item->id}/upload", ['file' => $upload, 'request_id' => (string) Str::uuid()])->assertAccepted()->json('data.id');
        $this->postJson("/api/editing-tasks/{$item->id}/submit")->assertConflict();
        app()->call([new ProcessMediaVersion($returned), 'handle']);
        $this->postJson("/api/editing-tasks/{$item->id}/submit")->assertOk();
        $this->postJson("/api/editing-tasks/{$item->id}/submit")->assertOk();
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('completed', $item->dispatch->fresh()->status);
        $this->assertNull(DB::table('shoot_service')->first()->editing_completed_at);
        $this->assertNull(DB::table('shoot_service')->first()->video_editing_completed_at);
        $this->assertSame('delivered', $this->shoot->fresh()->workflow_status);
        $this->assertSame('todo', $this->photo->fresh()->workflow_stage);
    }

    public function test_video_intake_assigns_the_lane_and_selected_video_return_publishes_link_without_completing_lanes(): void
    {
        $intake = $this->payload(['mode' => 'editor', 'scope' => 'videos']);
        unset($intake['file_ids']);
        $this->send($intake)->assertAccepted();
        $this->assertSame(0, ShootEditingDispatchItem::count());
        $this->assertSame($this->videoEditor->id, (int) DB::table('shoot_service')->first()->video_editor_id);
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $id = $this->send($this->payload(['mode' => 'editor', 'file_ids' => [$this->video->id]]))->assertAccepted()->json('data.dispatchId');
        $this->shoot->update(['tour_links' => ['video_branded' => 'https://example.test/branded']]);
        $this->actingAs($this->videoEditor);
        $item = ShootEditingDispatchItem::where('dispatch_id', $id)->firstOrFail();
        $this->postJson("/api/editing-tasks/{$item->id}/video", ['url' => 'https://example.test/finished'])->assertOk();
        $this->postJson("/api/editing-tasks/{$item->id}/submit")->assertOk();
        $this->postJson("/api/editing-tasks/{$item->id}/submit")->assertOk();
        $this->assertNull(DB::table('shoot_service')->first()->video_editing_completed_at);
        $this->assertNull(DB::table('shoot_service')->first()->editing_completed_at);
        $this->assertSame('delivered', $this->shoot->fresh()->workflow_status);
        $this->assertSame(['video_branded' => 'https://example.test/branded', 'video_link' => 'https://example.test/finished'], $this->shoot->fresh()->tour_links);
    }

    public function test_lanes_report_what_was_sent_so_the_remaining_lane_can_be_sent_later(): void
    {
        DB::table('shoot_service')->update(['editor_id' => null, 'video_editor_id' => null]);
        $this->getJson("/api/shoots/{$this->shoot->id}/editing-plan")->assertOk()
            ->assertJsonPath('data.lanes.photo', ['available' => true, 'sent' => false])
            ->assertJsonPath('data.lanes.video', ['available' => true, 'sent' => false]);
        $videos = $this->payload(['mode' => 'editor', 'scope' => 'videos']);
        unset($videos['file_ids']);
        $this->send($videos)->assertAccepted();
        $this->assertSame('editing', $this->shoot->fresh()->workflow_status);
        $this->assertSame($this->videoEditor->id, (int) DB::table('shoot_service')->first()->video_editor_id);
        $this->assertNull(DB::table('shoot_service')->first()->editor_id);
        $this->getJson("/api/shoots/{$this->shoot->id}/editing-plan")->assertOk()
            ->assertJsonPath('data.lanes.photo.sent', false)->assertJsonPath('data.lanes.video.sent', true);
        $photos = array_replace($videos, ['scope' => 'photos', 'request_id' => (string) Str::uuid()]);
        $this->send($photos)->assertAccepted();
        $this->assertSame($this->photoEditor->id, (int) DB::table('shoot_service')->first()->editor_id);
        $this->getJson("/api/shoots/{$this->shoot->id}/editing-plan")->assertOk()
            ->assertJsonPath('data.lanes.photo.sent', true)->assertJsonPath('data.lanes.video.sent', true);
        $this->assertSame(0, ShootEditingDispatchItem::count());
    }

    public function test_shoots_sent_before_lane_requests_report_both_lanes_sent(): void
    {
        $this->shoot->update(['status' => 'editing', 'workflow_status' => 'editing']);
        $this->getJson("/api/shoots/{$this->shoot->id}/editing-plan")->assertOk()
            ->assertJsonPath('data.lanes.photo.sent', true)->assertJsonPath('data.lanes.video.sent', true);
    }

    public function test_ai_partial_results_publish_once_keep_alternatives_and_preserve_other_items(): void
    {
        $other = $this->photo->replicate(); $other->filename = 'bedroom.jpg'; $other->save(); $other->refresh();
        $data = $this->payload(['file_ids' => [$this->photo->id, $other->id], 'source_versions' => [$this->photo->id => 1, $other->id => 1], 'preset' => 'green-grass']);
        $id = $this->send($data)->assertAccepted()->json('data.dispatchId');
        app()->call([new PrepareEditingDispatch($id), 'handle']);
        $workspace = StudioWorkspace::firstOrFail();
        $image = UploadedFile::fake()->image('result.jpg', 48, 32);
        Storage::disk('public')->put('studio/result.jpg', file_get_contents($image->getRealPath()));
        $publisher = app(\App\Services\Studio\ScopedWorkspacePublisher::class);
        foreach (['primary', 'primary', 'alternative'] as $output) $publisher->publish($workspace, ['id' => 'file-'.$this->photo->id, 'fileId' => $this->photo->id], ['path' => 'studio/result.jpg'], $output);
        $this->assertSame(2, ShootFileVersion::count());
        foreach (ShootFileVersion::all() as $version) app()->call([new ProcessMediaVersion($version->id), 'handle']);
        $published = ShootFileVersion::where('status', 'published')->firstOrFail();
        $alternative = ShootFileVersion::where('status', 'alternative')->firstOrFail();
        $output = ShootFile::findOrFail($published->published_file_id);
        $this->assertSame('green_grass', $output->treatment);
        $this->assertSame($this->photo->shoot_service_id, $output->shoot_service_id);
        $this->assertSame(1, ShootEditingDispatchItem::where('status', 'completed')->count());
        $this->assertSame('in_progress', ShootEditingDispatch::findOrFail($id)->status);
        $this->getJson("/api/shoots/{$this->shoot->id}/files/{$output->id}/versions")->assertOk()->assertJsonCount(2, 'data');
        app(\App\Services\Shoots\MediaVersionPublisher::class)->resolve($alternative, 'replace_latest', 1);
        $this->assertSame(2, $output->fresh()->content_version);
        $this->assertSame('todo', $this->photo->fresh()->workflow_stage);
        $this->assertNull(DB::table('shoot_service')->first()->editing_completed_at);
    }
}
