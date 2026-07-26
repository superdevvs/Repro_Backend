<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint coverage for Studio projects.
 *
 * Validates: Requirements 9.1-9.3, 13.15, 15.2, 15.6, 15.7, 16.7.
 */
class StudioProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-12 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_index_returns_each_team_project_once_in_server_activity_order_with_required_summary(): void
    {
        $admin = $this->teamUser('admin', 44);
        $editor = $this->teamUser('editor', 44);
        $peer = $this->teamUser('editor', 44);
        $outsider = $this->teamUser('editor', 55);
        $shoot = Shoot::factory()->create(['address' => '14 Activity Lane', 'hero_image' => '/shoot/hero.jpg']);

        $older = $this->project($editor, 44, $shoot, 'Older Project', 'photo-enhancement');
        $newer = $this->project($peer, 44, $shoot, 'Newest Project', 'listing-video');
        $outside = $this->project($outsider, 55, $shoot, 'Outside Project', 'photo-enhancement');
        $this->setUpdatedAt($older, now()->subDays(3));
        $this->setUpdatedAt($newer, now()->subDays(2));

        ProjectMedia::create([
            'project_id' => $older->id,
            'team_id' => 44,
            'created_by' => $editor->id,
            'media_ref' => '/media/source.jpg',
            'kind' => 'source',
        ]);
        ProjectMedia::create([
            'project_id' => $older->id,
            'team_id' => 44,
            'created_by' => $editor->id,
            'media_ref' => '/media/output.jpg',
            'kind' => 'output',
        ]);

        $oldJob = $this->photoJob($editor, $older, $shoot, [
            'status' => AiEditingJob::STATUS_PROCESSING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
        ]);
        $latestJob = $this->photoJob($editor, $older, $shoot, [
            'status' => AiEditingJob::STATUS_COMPLETED,
            'editing_type' => AiEditingJob::TYPE_SKY_REPLACE,
        ]);
        $this->setUpdatedAt($oldJob, now()->subDays(2));
        $this->setUpdatedAt($latestJob, now()->subHour());
        $this->photoJob($outsider, $outside, $shoot);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/studio/projects')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.count', 2);

        $projects = collect($response->json('data'));
        $this->assertSame([$older->id, $newer->id], $projects->pluck('id')->all());
        $this->assertSame(2, $projects->pluck('id')->unique()->count());
        $this->assertNotContains($outside->id, $projects->pluck('id')->all());

        $summary = $projects->first();
        $this->assertSame('/media/output.jpg', $summary['thumbnailRef']);
        $this->assertSame('twilight', $summary['latestWorkflowId']);
        $this->assertSame('Twilight', $summary['latestWorkflow']);
        $this->assertSame(AiEditingJob::STATUS_COMPLETED, $summary['latestStatus']);
        $this->assertSame(now()->subHour()->toISOString(), $summary['lastActivityAt']);
        $this->assertSame(2, $summary['mediaCount']);
        $this->assertSame('projects', $summary['deepLink']['destination']);
        $this->assertArrayNotHasKey('teamId', $summary);
        $this->assertArrayNotHasKey('createdBy', $summary);
    }

    public function test_editor_list_is_self_scoped_while_privileged_list_is_team_scoped(): void
    {
        $admin = $this->teamUser('editing_manager', 44);
        $editor = $this->teamUser('editor', 44);
        $peer = $this->teamUser('editor', 44);
        $outsider = $this->teamUser('editor', 55);
        $shoot = Shoot::factory()->create();
        $own = $this->project($editor, 44, $shoot, 'Own');
        $teamPeer = $this->project($peer, 44, $shoot, 'Peer');
        $outside = $this->project($outsider, 55, $shoot, 'Outside');

        Sanctum::actingAs($editor);
        $editorIds = collect($this->getJson('/api/studio/projects')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$own->id], $editorIds->all());

        Sanctum::actingAs($admin);
        $teamIds = collect($this->getJson('/api/studio/projects')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$own->id, $teamPeer->id], $teamIds->all());
        $this->assertNotContains($outside->id, $teamIds->all());
    }

    public function test_detail_returns_safe_server_backed_data_and_rejects_missing_or_out_of_scope_projects(): void
    {
        $editor = $this->teamUser('editor', 44);
        $peer = $this->teamUser('editor', 44);
        $outsider = $this->teamUser('editor', 55);
        $shoot = Shoot::factory()->create(['address' => '8 Secure Court']);
        $own = $this->project($editor, 44, $shoot, 'Secure Project');
        $peerProject = $this->project($peer, 44, $shoot, 'Peer Project');
        $outsideProject = $this->project($outsider, 55, $shoot, 'Outside Project');
        $media = ProjectMedia::create([
            'project_id' => $own->id,
            'team_id' => 44,
            'created_by' => $editor->id,
            'media_ref' => '/secure/source.jpg',
            'kind' => 'source',
        ]);
        $job = $this->videoJob($editor, $own, $shoot, [
            'status' => AiListingVideoJob::STATUS_PROCESSING,
            'provider_request_ids' => ['secret-provider-request'],
            'request_id' => 'secret-idempotency-key',
        ]);

        Sanctum::actingAs($editor);
        $response = $this->getJson('/api/studio/projects/'.$own->id)
            ->assertOk()
            ->assertJsonPath('data.id', $own->id)
            ->assertJsonPath('data.media.0.id', $media->id)
            ->assertJsonPath('data.media.0.mediaRef', '/secure/source.jpg')
            ->assertJsonPath('data.jobs.0.id', 'video-'.$job->id)
            ->assertJsonPath('data.jobs.0.workflowTitle', 'Listing Video');

        $detail = $response->json('data');
        $this->assertArrayNotHasKey('team_id', $detail);
        $this->assertArrayNotHasKey('created_by', $detail);
        $this->assertArrayNotHasKey('provider_request_ids', $detail['jobs'][0]);
        $this->assertArrayNotHasKey('request_id', $detail['jobs'][0]);

        $this->getJson('/api/studio/projects/'.$peerProject->id)->assertForbidden();
        $this->getJson('/api/studio/projects/'.$outsideProject->id)->assertForbidden();
        $this->getJson('/api/studio/projects/00000000-0000-0000-0000-000000000000')->assertNotFound();

        $admin = $this->teamUser('admin', 44);
        Sanctum::actingAs($admin);
        $this->getJson('/api/studio/projects/'.$peerProject->id)->assertOk();
        $this->getJson('/api/studio/projects/'.$outsideProject->id)->assertForbidden();
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function project(
        User $owner,
        int $teamId,
        Shoot $shoot,
        string $name,
        string $workflow = 'photo-enhancement'
    ): Project {
        return Project::create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => $shoot->id,
            'name' => $name,
            'address' => $shoot->address,
            'source_type' => 'shoot',
            'workflow_id' => $workflow,
            'status' => 'submitted',
        ]);
    }

    private function photoJob(User $owner, Project $project, Shoot $shoot, array $overrides = []): AiEditingJob
    {
        return AiEditingJob::create(array_merge([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'status' => AiEditingJob::STATUS_PENDING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => '/media/original.jpg',
        ], $overrides));
    }

    private function videoJob(User $owner, Project $project, Shoot $shoot, array $overrides = []): AiListingVideoJob
    {
        return AiListingVideoJob::create(array_merge([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'provider' => 'fal',
            'selected_file_ids' => [1],
            'target_seconds' => 30,
            'status' => AiListingVideoJob::STATUS_QUEUED,
            'total_clips' => 1,
            'completed_clips' => 0,
        ], $overrides));
    }

    private function setUpdatedAt(Project|AiEditingJob|AiListingVideoJob $model, Carbon $at): void
    {
        DB::table($model->getTable())->where('id', $model->getKey())->update(['updated_at' => $at]);
        $model->refresh();
    }
}
