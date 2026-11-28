<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shoot 6038 (812 Tritapoe Drive): returned edits stuck in "conflict" on photos
 * that were then deleted blocked finalise, with no screen left to clear them.
 */
class OrphanedReturnedEditsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Shoot $shoot;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests(); Storage::fake('local'); Storage::fake('public');
        $this->admin = User::factory()->create(['role' => 'superadmin']);
        $this->shoot = Shoot::factory()->create(['status' => 'editing', 'workflow_status' => 'editing']);
    }

    private function photo(string $name = 'Front.jpg'): ShootFile
    {
        return ShootFile::create(['shoot_id' => $this->shoot->id, 'filename' => $name, 'stored_filename' => $name,
            'path' => "shoots/{$this->shoot->id}/completed/{$name}", 'file_type' => 'image/jpeg', 'mime_type' => 'image/jpeg',
            'file_size' => 100, 'media_type' => 'photo', 'workflow_stage' => 'verified', 'scan_status' => 'clean',
            'uploaded_by' => $this->admin->id])->fresh();
    }

    private function returnedEdit(?int $source, ?int $target, string $status = 'conflict', array $attributes = []): ShootFileVersion
    {
        return ShootFileVersion::create(array_replace(['shoot_id' => $this->shoot->id, 'source_file_id' => $source,
            'target_file_id' => $target, 'expected_version' => 1, 'status' => $status, 'created_by' => $this->admin->id,
            'request_key' => 'intake:'.uniqid('', true), 'metadata' => ['origin' => 'intake'],
            'error' => 'This changes delivered content. Review returned edit and choose Replace latest or Save as copy.'], $attributes));
    }

    private function finalize()
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/shoots/{$this->shoot->id}/upload/finalize-edited");
    }

    public function test_an_open_returned_edit_still_blocks_finalise(): void
    {
        $photo = $this->photo();
        $this->returnedEdit($photo->id, $photo->id);

        $this->finalize()->assertStatus(409)->assertJsonPath('error_type', 'saved_edits_pending');
    }

    public function test_deleting_the_photo_dismisses_its_open_returned_edits_and_unblocks_finalise(): void
    {
        $photo = $this->photo();
        $keep = $this->photo('Kitchen.jpg');
        $conflict = $this->returnedEdit($photo->id, $photo->id);
        $queued = $this->returnedEdit($photo->id, $photo->id, 'queued');
        $published = $this->returnedEdit($photo->id, null, 'published', ['published_file_id' => $photo->id, 'version' => 1]);
        $otherPhoto = $this->returnedEdit($keep->id, $keep->id);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/shoots/{$this->shoot->id}/media/{$photo->id}")->assertOk();

        $fresh = $conflict->fresh();
        $this->assertSame('dismissed', $fresh->status);
        $this->assertSame('file_deleted', $fresh->metadata['dismissed_reason']);
        $this->assertSame('intake', $fresh->metadata['origin']);
        // In-flight versions stay with the processing job (it records source_deleted),
        // and no longer block finalise because their photo is gone.
        $this->assertSame('queued', $queued->fresh()->status);
        $this->assertSame('published', $published->fresh()->status);
        $this->assertSame('conflict', $otherPhoto->fresh()->status);

        // The other photo's conflict is real and still blocks.
        $this->finalize()->assertStatus(409)->assertJsonPath('error_type', 'saved_edits_pending');
        $otherPhoto->update(['status' => 'dismissed']);
        $this->assertNotSame('saved_edits_pending', $this->finalize()->json('error_type'));
    }

    public function test_returned_edit_onto_another_photo_survives_deleting_its_source(): void
    {
        $raw = $this->photo('Raw.jpg');
        $target = $this->photo('Edited.jpg');
        $edit = $this->returnedEdit($raw->id, $target->id);

        $raw->delete();

        $this->assertSame('conflict', $edit->fresh()->status);
    }

    public function test_editor_task_returns_are_left_to_the_task_workflow(): void
    {
        $photo = $this->photo();
        $task = $this->returnedEdit($photo->id, $photo->id, 'conflict', [
            'metadata' => ['origin' => 'human'], 'dispatch_item_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $photo->delete();

        // Same rule as the Dismiss button: task returns resolve through the task.
        $this->assertSame('conflict', $task->fresh()->status);
    }

    public function test_existing_orphans_whose_photo_is_already_gone_no_longer_block_finalise(): void
    {
        // Rows created before this fix: the photo was deleted, the conflict stayed.
        $this->returnedEdit(999001, 999001);
        $this->returnedEdit(999002, null, 'failed');

        $this->assertNotSame('saved_edits_pending', $this->finalize()->json('error_type'));
    }
}
