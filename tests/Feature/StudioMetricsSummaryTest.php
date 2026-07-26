<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint tests for the trailing-30-day Studio metrics summary.
 *
 * Validates: Requirements 8.1–8.8, 8.12, 16.6
 */
class StudioMetricsSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/metrics/summary';
    private const TEAM_ID = 44;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_returns_server_window_and_zero_values_without_terminal_jobs(): void
    {
        Carbon::setTestNow('2026-07-30T12:00:00+00:00');
        $editor = $this->user('editor', self::TEAM_ID);
        $project = $this->project($editor);
        $this->photoJob($project, $editor, AiEditingJob::STATUS_CANCELLED, now()->subDay());
        Sanctum::actingAs($editor);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.projectsProcessed', 1)
            ->assertJsonPath('data.aiJobsCompleted', 0)
            ->assertJsonPath('data.successRate', 0)
            ->assertJsonPath('data.mediaOutputs', 0)
            ->assertJsonPath('data.windowStart', '2026-06-30T12:00:00+00:00')
            ->assertJsonPath('data.windowEnd', '2026-07-30T12:00:00+00:00');
    }

    public function test_summary_deduplicates_projects_and_counts_terminal_jobs_and_persisted_outputs(): void
    {
        Carbon::setTestNow('2026-07-30T12:00:00+00:00');
        $admin = $this->user('admin', self::TEAM_ID);
        $teammate = $this->user('editor', self::TEAM_ID);
        $outsider = $this->user('editor', 99);
        $first = $this->project($teammate);
        $second = $this->project($teammate);
        $outside = $this->project($outsider, 99);

        $this->photoJob($first, $teammate, AiEditingJob::STATUS_COMPLETED, now()->subDays(30));
        $this->videoJob($first, $teammate, AiListingVideoJob::STATUS_COMPLETED, now());
        $this->photoJob($second, $teammate, AiEditingJob::STATUS_FAILED, now()->subDay());
        $this->videoJob($second, $teammate, AiListingVideoJob::STATUS_CANCELLED, now()->subHours(2));
        $this->photoJob($outside, $outsider, AiEditingJob::STATUS_COMPLETED, now()->subDay());
        $this->photoJob($second, $teammate, AiEditingJob::STATUS_COMPLETED, now()->subDays(31));

        $this->media($first, $teammate, 'output', now()->subDays(10));
        $this->media($first, $teammate, 'output', now());
        $this->media($first, $teammate, 'source', now());
        $this->media($first, $teammate, 'output', now()->subDays(31));
        $this->media($second, $teammate, 'output', now());
        $this->media($outside, $outsider, 'output', now()->subDay(), 99);

        Sanctum::actingAs($admin);
        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.projectsProcessed', 2)
            ->assertJsonPath('data.aiJobsCompleted', 2)
            ->assertJsonPath('data.successRate', 66.7)
            ->assertJsonPath('data.mediaOutputs', 2);
    }

    public function test_editor_summary_is_owner_scoped_within_the_team(): void
    {
        Carbon::setTestNow('2026-07-30T12:00:00+00:00');
        $editor = $this->user('editor', self::TEAM_ID);
        $other = $this->user('editor', self::TEAM_ID);
        $ownProject = $this->project($editor);
        $otherProject = $this->project($other);
        $this->photoJob($ownProject, $editor, AiEditingJob::STATUS_COMPLETED, now());
        $this->photoJob($otherProject, $other, AiEditingJob::STATUS_COMPLETED, now());
        $this->media($ownProject, $editor, 'output', now());
        $this->media($otherProject, $other, 'output', now());

        Sanctum::actingAs($editor);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.projectsProcessed', 1)
            ->assertJsonPath('data.aiJobsCompleted', 1)->assertJsonPath('data.successRate', 100)
            ->assertJsonPath('data.mediaOutputs', 1);
    }

    private function user(string $role, int $teamId): User
    {
        return User::factory()->create(['role' => $role, 'metadata' => ['team_id' => $teamId]]);
    }

    private function project(User $owner, int $teamId = self::TEAM_ID): Project
    {
        return Project::create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => \App\Models\Shoot::factory()->create()->id,
            'name' => 'Metrics project',
            'source_type' => 'shoot',
            'workflow_id' => 'photo-enhancement',
            'status' => 'submitted',
        ]);
    }

    private function photoJob(Project $project, User $owner, string $status, Carbon $at): AiEditingJob
    {
        $job = AiEditingJob::create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'status' => $status,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => 'https://example.test/photo.jpg',
            'completed_at' => $at,
        ]);
        DB::table('ai_editing_jobs')->where('id', $job->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
            'completed_at' => $at,
        ]);

        return $job;
    }

    private function videoJob(Project $project, User $owner, string $status, Carbon $at): AiListingVideoJob
    {
        $job = AiListingVideoJob::create([
            'project_id' => $project->id,
            'shoot_id' => $project->shoot_id,
            'user_id' => $owner->id,
            'provider' => 'fal',
            'selected_file_ids' => [1],
            'target_seconds' => 30,
            'status' => $status,
            'completed_at' => $at,
        ]);
        DB::table('ai_listing_video_jobs')->where('id', $job->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
            'completed_at' => $at,
        ]);

        return $job;
    }

    private function media(
        Project $project,
        User $owner,
        string $kind,
        Carbon $at,
        int $teamId = self::TEAM_ID
    ): ProjectMedia {
        $media = ProjectMedia::create([
            'project_id' => $project->id,
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'media_ref' => 'studio/' . $kind . '-' . uniqid() . '.jpg',
            'kind' => $kind,
        ]);
        DB::table('project_media')->where('id', $media->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $media;
    }
}
