<?php

namespace Tests\Feature;

use App\Models\{Service, Shoot, ShootEditingDispatch, ShootEditingDispatchItem, ShootFile, User};
use App\Services\Shoots\{ShootAuthorizationSupport, ShootDispatchOverviewReturns, ShootEditingAssignmentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue, Storage};
use Tests\TestCase;

class EditorOverviewDispatchReturnsTest extends TestCase
{
    use RefreshDatabase;
    private User $editor;
    private Shoot $shoot;
    private ShootEditingDispatchItem $item;
    private ShootFile $source;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
        $this->editor = User::factory()->create(['role' => 'editor', 'metadata' => ['editing_capabilities' => ['photo', 'video']]]);
        $this->shoot = Shoot::factory()->create(['status' => 'editing', 'workflow_status' => 'editing', 'editor_id' => null]);
        $service = Service::factory()->create(['name' => 'Photos', 'upload_intake_type' => 'photo']);
        $this->shoot->services()->attach($service, ['price' => 100, 'quantity' => 1]);
        $this->source = $this->file(['workflow_stage' => 'todo', 'uploaded_by' => $this->shoot->client_id]);
        $dispatch = ShootEditingDispatch::create(['shoot_id' => $this->shoot->id, 'scope' => 'selected', 'destination' => 'human',
            'workflow' => 'listing-ready', 'instructions' => 'Correct this source', 'status' => 'assigned',
            'input_hash' => str_repeat('a', 64), 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => $this->shoot->client_id, 'plan' => []]);
        $this->item = ShootEditingDispatchItem::create(['dispatch_id' => $dispatch->id, 'editor_id' => $this->editor->id,
            'input_key' => 'source:1', 'destination' => 'human', 'lane' => 'photo', 'workflow' => 'listing-ready', 'status' => 'assigned',
            'sources' => [['id' => $this->source->id, 'version' => $this->source->content_version, 'name' => $this->source->filename]]]);
    }

    private function file(array $attributes = []): ShootFile
    {
        $file = ShootFile::create(array_replace(['shoot_id' => $this->shoot->id, 'filename' => 'return.jpg',
            'stored_filename' => 'return.jpg', 'path' => 'shoots/test/return.jpg', 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 100, 'media_type' => 'photo', 'workflow_stage' => 'completed', 'scan_status' => 'clean',
            'uploaded_by' => $this->editor->id], $attributes));
        $file->forceFill($attributes)->save();
        return $file->fresh();
    }

    public function test_task_only_editor_can_open_overview_and_submit_ordinary_upload_without_changing_assignments(): void
    {
        $output = $this->file();
        $unrelated = $this->file(['uploaded_by' => $this->shoot->client_id]);
        $this->actingAs($this->editor)->getJson('/api/shoots/'.$this->shoot->id)->assertOk();
        $access = app(ShootEditingAssignmentService::class);
        $this->assertTrue($access->canEditorAccessFile($this->shoot, $this->source, $this->editor));
        $this->assertTrue($access->canEditorAccessFile($this->shoot, $output, $this->editor));
        $this->assertFalse($access->canEditorAccessFile($this->shoot, $unrelated, $this->editor));
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canScheduleShoot($this->shoot, $this->editor));
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canVideoEditorReadClientContext($this->shoot, $this->editor));
        $this->postJson('/api/shoots/'.$this->shoot->id.'/upload/finalize-edited')->assertOk()->assertJsonPath('editing_submission_changed', true);
        $this->assertSame('completed', $this->item->fresh()->status);
        $this->assertSame($output->id, $this->item->fresh()->overview_return_files[0]['id']);
        $this->assertNull($this->shoot->fresh()->editor_id);
        $this->assertNull(DB::table('shoot_service')->where('shoot_id', $this->shoot->id)->value('editor_id'));
        $this->postJson('/api/shoots/'.$this->shoot->id.'/upload/finalize-edited')->assertOk()->assertJsonPath('workflow_status_changed', false);
        Mail::assertNothingSent(); Notification::assertNothingSent();
    }

    public function test_normal_edited_upload_accepts_the_dispatch_and_revokes_it_after_reassignment(): void
    {
        Storage::fake('public');
        $this->mock(\App\Services\ShootMediaStorageService::class)->shouldReceive('uploadToCompleted')->once()
            ->andReturnUsing(fn () => $this->file(['filename' => 'edited.jpg']));
        $this->actingAs($this->editor)->post('/api/shoots/'.$this->shoot->id.'/upload', [
            'upload_type' => 'edited', 'files' => [UploadedFile::fake()->image('edited.jpg', 8, 8)],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success_count', 1);
        $this->item->update(['editor_id' => User::factory()->create(['role' => 'editor'])->id]);
        $this->post('/api/shoots/'.$this->shoot->id.'/upload', [
            'upload_type' => 'edited', 'files' => [UploadedFile::fake()->image('another.jpg', 8, 8)],
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_hidden_quarantined_stale_other_editor_and_wrong_lane_outputs_cannot_complete_a_task(): void
    {
        $this->file(['is_hidden' => true]);
        $this->file(['scan_status' => 'quarantined']);
        $this->file(['flag_reason' => 'review']);
        $this->file(['created_at' => now()->subDay()]);
        $this->file(['uploaded_by' => $this->shoot->client_id]);
        $this->file(['media_type' => 'video', 'file_type' => 'video/mp4']);
        $result = app(ShootDispatchOverviewReturns::class)->submit($this->shoot, $this->editor);
        $this->assertSame(409, $result['status']);
        $this->assertSame('assigned', $this->item->fresh()->status);
        $this->actingAs(User::factory()->create(['role' => 'editor']))->getJson('/api/shoots/'.$this->shoot->id)->assertForbidden();
        $this->item->update(['status' => 'cancelled']);
        $this->assertFalse(app(ShootAuthorizationSupport::class)->canAccessShootMedia($this->shoot, $this->editor));
    }

    public function test_source_changes_and_partial_batches_remain_open(): void
    {
        $second = $this->item->replicate(); $second->input_key = 'source:2'; $second->save();
        $this->file();
        $returns = app(ShootDispatchOverviewReturns::class);
        $this->assertSame(409, $returns->submit($this->shoot, $this->editor)['status']);
        $this->file();
        $this->source->update(['content_version' => $this->source->content_version + 1]);
        $this->assertSame(409, $returns->submit($this->shoot, $this->editor)['status']);
        $this->assertSame(2, ShootEditingDispatchItem::where('status', 'assigned')->count());
    }

    public function test_video_return_and_delivered_revision_preserve_delivery_status(): void
    {
        $this->item->update(['lane' => 'video']);
        $this->assertTrue(app(ShootAuthorizationSupport::class)->canVideoEditorReadClientContext($this->shoot, $this->editor));
        $this->shoot->update(['status' => 'delivered', 'workflow_status' => 'delivered']);
        $this->file(['media_type' => 'video', 'filename' => 'return.mp4', 'file_type' => 'video/mp4']);
        $this->actingAs($this->editor)->postJson('/api/shoots/'.$this->shoot->id.'/upload/finalize-edited')
            ->assertOk()->assertJsonPath('workflow_status_changed', false);
        $this->assertSame('completed', $this->item->fresh()->status);
        $this->assertSame('delivered', $this->shoot->fresh()->workflow_status);
    }

    public function test_a_published_saved_version_of_an_existing_image_counts_as_the_editors_return(): void
    {
        $this->source->update(['workflow_stage' => 'completed', 'content_version' => 2]);
        \App\Models\ShootFileVersion::create(['shoot_id' => $this->shoot->id, 'source_file_id' => $this->source->id,
            'published_file_id' => $this->source->id, 'expected_version' => 1, 'version' => 2, 'status' => 'published',
            'created_by' => $this->editor->id, 'request_key' => 'overview-version-return']);
        $result = app(ShootDispatchOverviewReturns::class)->submit($this->shoot, $this->editor);
        $this->assertSame(1, $result['changed_count']);
        $this->assertSame([['id' => $this->source->id, 'version' => 2]], $this->item->fresh()->overview_return_files);
    }
}
