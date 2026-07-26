<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint coverage for Studio queue records.
 *
 * Validates: Requirements 7.1-7.3, 7.7-7.9, 7.14, 7.15,
 * 16.3-16.5, 15.2, 15.6, 15.7.
 */
class StudioQueueControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_index_returns_authorized_unified_records_with_truthful_queue_metadata(): void
    {
        $admin = $this->teamUser('admin', 44);
        $editor = $this->teamUser('editor', 44);
        $outsideEditor = $this->teamUser('editor', 55);
        $shoot = Shoot::factory()->create(['address' => '14 Queue Lane', 'hero_image' => '/media/queue.jpg']);
        $project = $this->project($editor, $shoot, 44);

        $photo = $this->photoJob($editor, $shoot, [
            'project_id' => $project->id,
            'status' => AiEditingJob::STATUS_PROCESSING,
            'provider_result' => ['data' => ['progress_percent' => 25]],
            'started_at' => now()->subSeconds(60),
        ]);
        $video = $this->videoJob($editor, $shoot, [
            'status' => AiListingVideoJob::STATUS_PROCESSING,
            'total_clips' => 4,
            'completed_clips' => 1,
            'started_at' => now()->subSeconds(60),
        ]);
        $failed = $this->photoJob($editor, $shoot, [
            'status' => AiEditingJob::STATUS_FAILED,
            'error_message' => 'Provider rejected the source image.',
            'completed_at' => now()->subHours(2),
        ]);
        $oldTerminal = $this->videoJob($editor, $shoot, [
            'status' => AiListingVideoJob::STATUS_COMPLETED,
            'completed_at' => now()->subHours(25),
            'total_clips' => 4,
            'completed_clips' => 4,
        ]);
        $outside = $this->photoJob($outsideEditor, $shoot);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/studio/queue')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.retentionHours', 24)
            ->assertJsonPath('meta.calculatedAt', now()->toISOString());

        $records = collect($response->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing(
            ['photo-' . $photo->id, 'video-' . $video->id, 'photo-' . $failed->id],
            $records->keys()->all()
        );
        $this->assertFalse($records->has('video-' . $oldTerminal->id));
        $this->assertFalse($records->has('photo-' . $outside->id));

        $photoRecord = $records['photo-' . $photo->id];
        $this->assertSame((string) $photo->id, $photoRecord['aiJobId']);
        $this->assertSame('photo', $photoRecord['jobType']);
        $this->assertSame('Photo Enhancement', $photoRecord['workflowTitle']);
        $this->assertSame('project', $photoRecord['context']['type']);
        $this->assertSame('Queue Project', $photoRecord['contextLabel']);
        $this->assertSame(25, $photoRecord['progress']);
        $this->assertSame(180, $photoRecord['eta']['estimateSeconds']);
        $this->assertSame(now()->toISOString(), $photoRecord['eta']['calculatedAt']);
        $this->assertNull($photoRecord['terminalAt']);
        $this->assertSame($photo->fresh()->updated_at->toISOString(), $photoRecord['version']);
        $this->assertSame('photo-' . $photo->id, $photoRecord['deepLink']['recordId']);

        $videoRecord = $records['video-' . $video->id];
        $this->assertSame(25, $videoRecord['progress']);
        $this->assertSame(180, $videoRecord['eta']['estimateSeconds']);
        $this->assertSame('/media/queue.jpg', $videoRecord['thumbnailUrl']);

        $failedRecord = $records['photo-' . $failed->id];
        $this->assertSame('Provider rejected the source image.', $failedRecord['failureReason']);
        $this->assertSame($failed->completed_at->toISOString(), $failedRecord['terminalAt']);
        $this->assertNull($failedRecord['eta']);
    }

    public function test_progress_is_clamped_and_absent_server_values_remain_null(): void
    {
        $editor = $this->teamUser('editor', 44);
        $shoot = Shoot::factory()->create();
        $overReported = $this->photoJob($editor, $shoot, [
            'provider_payload' => ['progress' => 140],
        ]);
        $noProgress = $this->photoJob($editor, $shoot, [
            'provider_payload' => ['state' => 'waiting'],
        ]);

        Sanctum::actingAs($editor);
        $records = collect($this->getJson('/api/studio/queue')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(100, $records['photo-' . $overReported->id]['progress']);
        $this->assertNull($records['photo-' . $overReported->id]['eta']);
        $this->assertNull($records['photo-' . $noProgress->id]['progress']);
        $this->assertNull($records['photo-' . $noProgress->id]['eta']);
    }

    public function test_detail_supports_deep_links_and_enforces_record_scope(): void
    {
        $editor = $this->teamUser('editor', 44);
        $teamPeer = $this->teamUser('editor', 44);
        $outsideEditor = $this->teamUser('editor', 55);
        $shoot = Shoot::factory()->create(['address' => '8 Secure Court']);
        $ownJob = $this->photoJob($editor, $shoot);
        $peerJob = $this->videoJob($teamPeer, $shoot);
        $outsideJob = $this->photoJob($outsideEditor, $shoot);

        Sanctum::actingAs($editor);
        $this->getJson('/api/studio/queue/photo-' . $ownJob->id)
            ->assertOk()
            ->assertJsonPath('data.id', 'photo-' . $ownJob->id)
            ->assertJsonPath('data.context.label', '8 Secure Court');
        $this->getJson('/api/studio/queue/video-' . $peerJob->id)->assertForbidden();
        $this->getJson('/api/studio/queue/not-a-queue-id')->assertNotFound();
        $this->getJson('/api/studio/queue/photo-999999')->assertNotFound();

        $admin = $this->teamUser('admin', 44);
        Sanctum::actingAs($admin);
        $this->getJson('/api/studio/queue/video-' . $peerJob->id)->assertOk();
        $this->getJson('/api/studio/queue/photo-' . $outsideJob->id)->assertForbidden();
    }

    public function test_editor_list_is_self_scoped_while_privileged_list_is_team_scoped(): void
    {
        $admin = $this->teamUser('admin', 44);
        $editor = $this->teamUser('editor', 44);
        $peer = $this->teamUser('editor', 44);
        $outsider = $this->teamUser('editor', 55);
        $shoot = Shoot::factory()->create();
        $own = $this->photoJob($editor, $shoot);
        $peerJob = $this->videoJob($peer, $shoot);
        $outside = $this->photoJob($outsider, $shoot);

        Sanctum::actingAs($editor);
        $editorIds = collect($this->getJson('/api/studio/queue')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing(['photo-' . $own->id], $editorIds->all());

        Sanctum::actingAs($admin);
        $adminIds = collect($this->getJson('/api/studio/queue')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing(
            ['photo-' . $own->id, 'video-' . $peerJob->id],
            $adminIds->all()
        );
        $this->assertNotContains('photo-' . $outside->id, $adminIds->all());
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function project(User $owner, Shoot $shoot, int $teamId): Project
    {
        return Project::create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => $shoot->id,
            'name' => 'Queue Project',
            'address' => $shoot->address,
            'source_type' => 'shoot',
            'workflow_id' => 'photo-enhancement',
            'status' => 'submitted',
        ]);
    }

    private function photoJob(User $owner, Shoot $shoot, array $overrides = []): AiEditingJob
    {
        return AiEditingJob::create(array_merge([
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'status' => AiEditingJob::STATUS_PENDING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => '/media/source.jpg',
        ], $overrides));
    }

    private function videoJob(User $owner, Shoot $shoot, array $overrides = []): AiListingVideoJob
    {
        return AiListingVideoJob::create(array_merge([
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'provider' => 'fal',
            'selected_file_ids' => [1, 2, 3, 4],
            'target_seconds' => 30,
            'status' => AiListingVideoJob::STATUS_QUEUED,
            'total_clips' => 4,
            'completed_clips' => 0,
        ], $overrides));
    }
}
