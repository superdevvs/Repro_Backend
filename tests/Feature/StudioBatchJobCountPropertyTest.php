<?php

namespace Tests\Feature;

use App\Jobs\ProcessAutoenhanceEditingJob;
use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 37: Batch job count matches valid file count
 *
 * For any Batch AI Jobs submission with n files where 1 <= n <= 100, exactly n photo
 * AI_Jobs are created, one per selected file.
 *
 * **Validates: Requirements 13.7**
 *
 * No PBT library is configured for PHPUnit, so a fixed seed drives 20 reproducible
 * submissions to `POST /api/studio/projects` with the `batch-ai-jobs` workflow.
 * Forced cases pin the inclusive boundaries (1 and 100) plus mid-range counts; the
 * remaining counts are generated from the seeded distribution. Both source types
 * (shoot selection and uploaded media) are exercised.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('ai-editing-studio-revamp')]
class StudioBatchJobCountPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/projects';

    private const WORKFLOW = 'batch-ai-jobs';

    private const CASE_COUNT = 20;

    private const SEED = 20260815;

    private const TEAM_ID = 4_820;

    private const MAX_FILES = 100;

    public function test_property_37_batch_job_count_matches_valid_file_count(): void
    {
        Queue::fake();
        Storage::fake('public');
        config()->set('studio_uploads.disk', 'public');

        $actor = $this->teamUser('admin', self::TEAM_ID);
        $shoot = Shoot::factory()->create([
            'client_id' => $actor->id,
            'editor_id' => $actor->id,
            'created_by' => $actor->id,
            'address' => '31 Batch Count Way',
        ]);
        $fileIds = $this->shootFiles($shoot, $actor, self::MAX_FILES);
        $uploadRefs = $this->uploadedMedia($actor, self::MAX_FILES);

        $counts = $this->countGenerator();
        $this->assertCount(self::CASE_COUNT, $counts);
        $this->assertGreaterThanOrEqual(self::CASE_COUNT, count($counts));

        Sanctum::actingAs($actor);
        mt_srand(self::SEED + 1);

        $coverage = array_fill_keys(['minimumBoundary', 'maximumBoundary', 'midRange', 'shootSource', 'uploadSource'], false);
        $expectedTotalJobs = 0;

        foreach ($counts as $index => $count) {
            $useUpload = $index % 4 === 3;
            $selected = $useUpload
                ? $this->sample($uploadRefs, $count)
                : $this->sample($fileIds, $count);

            $payload = [
                'request_id' => "p37-{$index}",
                'workflow_id' => self::WORKFLOW,
                'source_type' => $useUpload ? 'upload' : 'shoot',
                'workflow_config' => [],
            ];
            if ($useUpload) {
                $payload['media_refs'] = $selected;
            } else {
                $payload['shoot_id'] = $shoot->id;
                $payload['file_ids'] = $selected;
            }

            $counterexample = sprintf(
                'seed=%d case=%d sourceType=%s fileCount=%d selected=%s',
                self::SEED,
                $index,
                $useUpload ? 'upload' : 'shoot',
                $count,
                json_encode($selected, JSON_THROW_ON_ERROR)
            );

            $response = $this->postJson(self::URL, $payload);
            $response->assertCreated();

            $projectId = (string) $response->json('data.projectId');
            $jobs = AiEditingJob::query()->where('project_id', $projectId)->orderBy('id')->get();

            // Exactly n photo AI_Jobs exist for the submission (Req 13.7).
            $this->assertCount(
                $count,
                $jobs,
                'Photo AI_Job count does not match the selected file count. '.$counterexample
            );
            $this->assertCount(
                $count,
                (array) $response->json('data.aiJobIds'),
                'Returned AI_Job identifiers do not match the selected file count. '.$counterexample
            );

            // Every created job is a photo AI_Job; no video or reel jobs are produced.
            foreach ((array) $response->json('data.jobs') as $job) {
                $this->assertSame(
                    'photo',
                    $job['type'] ?? null,
                    'Batch AI Jobs produced a non-photo job type. '.$counterexample
                );
            }
            foreach ($jobs as $job) {
                $this->assertSame(
                    AiEditingJob::TYPE_ENHANCE,
                    $job->editing_type,
                    'Batch AI Jobs produced a non-photo editing type. '.$counterexample
                );
            }
            $this->assertSame(
                0,
                AiListingVideoJob::query()->where('project_id', $projectId)->count()
                    + AiReelJob::query()->where('project_id', $projectId)->count(),
                'Batch AI Jobs created video or reel jobs. '.$counterexample
            );

            // One job per selected file: the job-to-source mapping is a bijection.
            $jobSources = $useUpload
                ? $jobs->map(fn (AiEditingJob $job): string => (string) $job->original_image_url)->all()
                : $jobs->map(fn (AiEditingJob $job): int => (int) $job->shoot_file_id)->all();
            $expectedSources = $useUpload
                ? array_map(
                    fn (string $ref): string => Storage::disk('public')->url($ref),
                    $selected
                )
                : $selected;

            $this->assertSame(
                count($jobSources),
                count(array_unique($jobSources)),
                'Two photo AI_Jobs reference the same selected file. '.$counterexample
            );
            $this->assertEqualsCanonicalizing(
                $expectedSources,
                $jobSources,
                'Created photo AI_Jobs do not map one-to-one onto the selected files. '.$counterexample
            );
            $this->assertSame(
                $count,
                ProjectMedia::query()->where('project_id', $projectId)->count(),
                'Persisted source media count does not match the selected file count. '.$counterexample
            );

            $expectedTotalJobs += $count;
            $this->assertSame(
                $expectedTotalJobs,
                AiEditingJob::query()->count(),
                'Total photo AI_Job count drifted from the cumulative selected file count. '.$counterexample
            );

            $coverage['shootSource'] = $coverage['shootSource'] || !$useUpload;
            $coverage['uploadSource'] = $coverage['uploadSource'] || $useUpload;
            $coverage['minimumBoundary'] = $coverage['minimumBoundary'] || $count === 1;
            $coverage['maximumBoundary'] = $coverage['maximumBoundary'] || $count === self::MAX_FILES;
            $coverage['midRange'] = $coverage['midRange'] || ($count > 1 && $count < self::MAX_FILES);
        }

        $this->assertSame(self::CASE_COUNT, Project::query()->count());
        Queue::assertPushed(ProcessAutoenhanceEditingJob::class, $expectedTotalJobs);

        foreach ($coverage as $dimension => $seen) {
            $this->assertTrue($seen, "Generator did not cover {$dimension}.");
        }
    }

    /**
     * Deterministic file-count generator: forced boundaries first, then seeded counts.
     *
     * @return array<int, int>
     */
    private function countGenerator(): array
    {
        $counts = [1, self::MAX_FILES, 2, 3, 12, 8, 5, 10];
        mt_srand(self::SEED);

        while (count($counts) < self::CASE_COUNT) {
            $counts[] = mt_rand(0, 9) < 7
                ? mt_rand(1, 8)
                : mt_rand(1, 16);
        }

        return $counts;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $pool
     * @return array<int, T>
     */
    private function sample(array $pool, int $count): array
    {
        $indexes = range(0, count($pool) - 1);
        for ($i = count($indexes) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$indexes[$i], $indexes[$j]] = [$indexes[$j], $indexes[$i]];
        }

        return array_values(array_map(
            fn (int $index) => $pool[$index],
            array_slice($indexes, 0, $count)
        ));
    }

    /**
     * @return array<int, int>
     */
    private function shootFiles(Shoot $shoot, User $owner, int $count): array
    {
        return collect(range(1, $count))->map(fn (int $index): int => (int) ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "p37-{$index}.jpg",
            'stored_filename' => "p37-{$index}.jpg",
            'path' => "shoots/{$shoot->id}/p37-{$index}.jpg",
            'storage_path' => "shoots/{$shoot->id}/p37-{$index}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'uploaded_by' => $owner->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ])->id)->all();
    }

    /**
     * @return array<int, string>
     */
    private function uploadedMedia(User $owner, int $count): array
    {
        $prefix = 'studio/uploads/'.self::TEAM_ID.'/'.$owner->id.'/';

        return collect(range(1, $count))->map(function (int $index) use ($prefix): string {
            $path = "{$prefix}p37-upload-{$index}.jpg";
            Storage::disk('public')->put($path, "batch-image-{$index}");

            return $path;
        })->all();
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }
}
