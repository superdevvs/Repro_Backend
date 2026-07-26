<?php

namespace Tests\Feature;

use App\Jobs\GenerateListingVideo;
use App\Jobs\GenerateReel;
use App\Jobs\ProcessAutoenhanceEditingJob;
use App\Jobs\ProcessFalEditingJob;
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
 * Feature: ai-editing-studio-revamp, Property 44: Idempotent submission retries do not duplicate jobs
 *
 * **Validates: Requirements 16.11**
 *
 * A deterministic generator produces 24 cases: every supported Workflow is crossed with
 * both source types (shoot selection and uploaded media) and two valid source counts,
 * while the retry count rotates across 1, 2, and 3 retries so multi-retry cases are
 * covered without a full cross product. For each generated case the submission is sent
 * once and then retried with the same server-recognized request identifier: every retry
 * must return the original
 * committed result unchanged and create no duplicate Project, ProjectMedia, or AI_Job
 * records, and must dispatch no additional processing jobs. A final submission with a
 * distinct request identifier must create a distinct Project, proving idempotency is
 * keyed on the request identifier rather than suppressing legitimate submissions.
 */
class StudioIdempotentSubmissionPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const CASE_COUNT = 24;

    private const TEAM_ID = 5_211;

    private const PROJECT_URL = '/api/studio/projects';

    /** Workflow => [media type, minimum valid source count]. */
    private const WORKFLOWS = [
        'photo-enhancement' => ['media' => 'image', 'minimum' => 1],
        'twilight' => ['media' => 'image', 'minimum' => 1],
        'video-cleanup' => ['media' => 'video', 'minimum' => 1],
        'listing-video' => ['media' => 'image', 'minimum' => 6],
        'reel-generator' => ['media' => 'image', 'minimum' => 1],
        'batch-ai-jobs' => ['media' => 'image', 'minimum' => 1],
    ];

    private const SIZE_OFFSETS = [0, 1];

    /** Rotated per case rather than fully crossed; includes multi-retry counts. */
    private const RETRY_COUNTS = [1, 2, 3];

    /** @var array<string, int> */
    private array $shootIds = [];

    /** @var array<string, array<int, int>> */
    private array $fileIds = [];

    /** @var array<string, array<int, string>> */
    private array $mediaRefs = [];

    public function test_property_44_idempotent_submission_retries_do_not_duplicate_jobs(): void
    {
        Queue::fake();
        Storage::fake('public');
        config()->set('studio_uploads.disk', 'public');

        $actor = User::factory()->create([
            'role' => 'admin',
            'metadata' => ['team_id' => self::TEAM_ID],
        ]);
        $this->seedSources($actor);
        Sanctum::actingAs($actor);

        $cases = $this->casesGenerator();
        $this->assertCount(self::CASE_COUNT, $cases);
        $this->assertSame(
            count(self::WORKFLOWS) * 2 * count(self::SIZE_OFFSETS),
            self::CASE_COUNT,
            'CASE_COUNT must match the generated workflow x source type x source size coverage.'
        );
        $this->assertSame(
            array_keys(self::WORKFLOWS),
            array_values(array_unique(array_column($cases, 'workflow'))),
            'Generated cases must cover every supported workflow.'
        );
        $this->assertSame(
            ['shoot', 'upload'],
            array_values(array_unique(array_column($cases, 'sourceType'))),
            'Generated cases must cover both source types.'
        );
        $this->assertGreaterThan(
            1,
            count(array_unique(array_column($cases, 'size'))),
            'Generated cases must cover more than one source size.'
        );
        $this->assertGreaterThan(
            1,
            max(array_column($cases, 'retries')),
            'Generated cases must include at least one multi-retry case.'
        );

        foreach ($cases as $case) {
            $this->assertIdempotentCase($actor, $case);
        }
    }

    /**
     * @param  array{index: int, workflow: string, sourceType: string, size: int, retries: int, mutateRetry: bool}  $case
     */
    private function assertIdempotentCase(User $actor, array $case): void
    {
        $context = sprintf(
            'case=%d workflow=%s source=%s size=%d retries=%d mutated=%s',
            $case['index'],
            $case['workflow'],
            $case['sourceType'],
            $case['size'],
            $case['retries'],
            $case['mutateRetry'] ? 'yes' : 'no'
        );
        $requestId = sprintf('p44-%d-%s', $case['index'], $case['workflow']);
        $expectedDelta = $this->expectedDelta($case['workflow'], $case['size']);
        $before = $this->recordCounts();
        $beforeDispatches = $this->dispatchCounts();

        $first = $this->postJson(self::PROJECT_URL, $this->payload($case, $requestId));
        $first->assertCreated();
        $original = $first->json('data');
        $projectId = (string) $original['projectId'];

        $afterFirst = $this->recordCounts();
        $this->assertSame(
            $this->sum($before, $expectedDelta),
            $afterFirst,
            "Initial submission did not create the expected records ({$context})."
        );
        $this->assertSame(
            1,
            Project::query()->where('created_by', $actor->id)->where('request_id', $requestId)->count(),
            "Initial submission did not persist exactly one project for the request id ({$context})."
        );
        $this->assertSame(
            $this->expectedJobsForRequestId($case['workflow'], $case['size']),
            $this->jobCountsForRequestId($requestId),
            "Initial submission did not persist the expected AI_Jobs for the request id ({$context})."
        );
        $afterFirstDispatches = $this->dispatchCounts();
        $this->assertSame(
            $this->sum($beforeDispatches, $this->expectedDispatchDelta($case['workflow'], $case['size'])),
            $afterFirstDispatches,
            "Initial submission did not dispatch the expected processing jobs ({$context})."
        );

        for ($attempt = 1; $attempt <= $case['retries']; $attempt++) {
            $retry = $this->postJson(
                self::PROJECT_URL,
                $case['mutateRetry']
                    ? $this->mutatedRetryPayload($requestId)
                    : $this->payload($case, $requestId)
            );

            $retry->assertOk();
            $this->assertSame(
                $original,
                $retry->json('data'),
                "Retry {$attempt} did not return the original committed result ({$context})."
            );
            $this->assertSame(
                $afterFirst,
                $this->recordCounts(),
                "Retry {$attempt} duplicated Studio records ({$context})."
            );
            $this->assertSame(
                $afterFirstDispatches,
                $this->dispatchCounts(),
                "Retry {$attempt} dispatched duplicate processing jobs ({$context})."
            );
            $this->assertSame(
                $this->caseName($case),
                (string) Project::query()->findOrFail($projectId)->name,
                "Retry {$attempt} mutated the original committed project ({$context})."
            );
        }

        $distinctRequestId = $requestId . '-distinct';
        $distinct = $this->postJson(self::PROJECT_URL, $this->payload($case, $distinctRequestId));
        $distinct->assertCreated();
        $this->assertNotSame(
            $projectId,
            (string) $distinct->json('data.projectId'),
            "A distinct request id reused the original project ({$context})."
        );
        $this->assertSame(
            $this->sum($afterFirst, $expectedDelta),
            $this->recordCounts(),
            "A distinct request id did not create a distinct set of records ({$context})."
        );
    }

    /**
     * @return array<int, array{index: int, workflow: string, sourceType: string, size: int, retries: int, mutateRetry: bool}>
     */
    private function casesGenerator(): array
    {
        $cases = [];
        $index = 0;

        foreach (self::WORKFLOWS as $workflow => $shape) {
            foreach (['shoot', 'upload'] as $sourceType) {
                foreach (self::SIZE_OFFSETS as $offset) {
                    $cases[] = [
                        'index' => $index,
                        'workflow' => $workflow,
                        'sourceType' => $sourceType,
                        'size' => $shape['minimum'] + $offset,
                        'retries' => self::RETRY_COUNTS[$index % count(self::RETRY_COUNTS)],
                        'mutateRetry' => $index % 2 === 1,
                    ];
                    $index++;
                }
            }
        }

        return $cases;
    }

    /**
     * @param  array{index: int, workflow: string, sourceType: string, size: int, retries: int, mutateRetry: bool}  $case
     * @return array<string, mixed>
     */
    private function payload(array $case, string $requestId): array
    {
        $media = self::WORKFLOWS[$case['workflow']]['media'];
        $payload = [
            'request_id' => $requestId,
            'workflow_id' => $case['workflow'],
            'source_type' => $case['sourceType'],
            'name' => $this->caseName($case),
            'workflow_config' => [],
        ];

        if ($case['sourceType'] === 'shoot') {
            $payload['shoot_id'] = $this->shootIds[$media];
            $payload['file_ids'] = array_slice($this->fileIds[$media], 0, $case['size']);

            return $payload;
        }

        $payload['media_refs'] = array_slice($this->mediaRefs[$media], 0, $case['size']);

        return $payload;
    }

    /**
     * A retry may legitimately carry a different body; the request id alone decides identity.
     *
     * @return array<string, mixed>
     */
    private function mutatedRetryPayload(string $requestId): array
    {
        return [
            'request_id' => $requestId,
            'workflow_id' => 'missing-workflow',
            'name' => 'P44 Mutated Retry',
        ];
    }

    /**
     * @param  array{index: int, workflow: string, sourceType: string, size: int, retries: int, mutateRetry: bool}  $case
     */
    private function caseName(array $case): string
    {
        return 'P44 Case ' . $case['index'];
    }

    /**
     * @return array<string, int>
     */
    private function expectedDelta(string $workflow, int $size): array
    {
        return array_merge(
            ['projects' => 1, 'projectMedia' => $size],
            $this->expectedJobsForRequestId($workflow, $size)
        );
    }

    /**
     * @return array<string, int>
     */
    private function expectedJobsForRequestId(string $workflow, int $size): array
    {
        return match ($workflow) {
            'listing-video' => ['photoJobs' => 0, 'listingVideoJobs' => 1, 'reelJobs' => 0],
            'reel-generator' => ['photoJobs' => 0, 'listingVideoJobs' => 0, 'reelJobs' => 1],
            default => ['photoJobs' => $size, 'listingVideoJobs' => 0, 'reelJobs' => 0],
        };
    }

    /**
     * @return array<string, int>
     */
    private function expectedDispatchDelta(string $workflow, int $size): array
    {
        return match ($workflow) {
            'listing-video' => ['autoenhance' => 0, 'fal' => 0, 'listingVideo' => 1, 'reel' => 0],
            'reel-generator' => ['autoenhance' => 0, 'fal' => 0, 'listingVideo' => 0, 'reel' => 1],
            'video-cleanup' => ['autoenhance' => 0, 'fal' => 0, 'listingVideo' => 0, 'reel' => 0],
            default => ['autoenhance' => $size, 'fal' => 0, 'listingVideo' => 0, 'reel' => 0],
        };
    }

    /**
     * @return array<string, int>
     */
    private function recordCounts(): array
    {
        return [
            'projects' => Project::query()->count(),
            'projectMedia' => ProjectMedia::query()->count(),
            'photoJobs' => AiEditingJob::query()->count(),
            'listingVideoJobs' => AiListingVideoJob::query()->count(),
            'reelJobs' => AiReelJob::query()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function jobCountsForRequestId(string $requestId): array
    {
        return [
            'photoJobs' => AiEditingJob::query()->where('request_id', $requestId)->count(),
            'listingVideoJobs' => AiListingVideoJob::query()->where('request_id', $requestId)->count(),
            'reelJobs' => AiReelJob::query()->where('request_id', $requestId)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function dispatchCounts(): array
    {
        return [
            'autoenhance' => Queue::pushed(ProcessAutoenhanceEditingJob::class)->count(),
            'fal' => Queue::pushed(ProcessFalEditingJob::class)->count(),
            'listingVideo' => Queue::pushed(GenerateListingVideo::class)->count(),
            'reel' => Queue::pushed(GenerateReel::class)->count(),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $delta
     * @return array<string, int>
     */
    private function sum(array $counts, array $delta): array
    {
        foreach ($delta as $key => $value) {
            $counts[$key] = ($counts[$key] ?? 0) + $value;
        }

        return $counts;
    }

    private function seedSources(User $actor): void
    {
        $imageShoot = $this->shoot($actor, '44 Idempotent Image Way');
        $videoShoot = $this->shoot($actor, '44 Idempotent Video Way');
        $this->shootIds = ['image' => $imageShoot->id, 'video' => $videoShoot->id];
        $this->fileIds = [
            'image' => $this->files($imageShoot, $actor, 8, 'image')->pluck('id')->all(),
            'video' => $this->files($videoShoot, $actor, 3, 'video')->pluck('id')->all(),
        ];

        $prefix = 'studio/uploads/' . self::TEAM_ID . '/' . $actor->id . '/';
        $this->mediaRefs = ['image' => [], 'video' => []];
        foreach (range(1, 8) as $index) {
            $ref = "{$prefix}p44-image-{$index}.jpg";
            Storage::disk('public')->put($ref, "image-{$index}");
            $this->mediaRefs['image'][] = $ref;
        }
        foreach (range(1, 3) as $index) {
            $ref = "{$prefix}p44-video-{$index}.mp4";
            Storage::disk('public')->put($ref, "video-{$index}");
            $this->mediaRefs['video'][] = $ref;
        }
    }

    private function shoot(User $owner, string $address): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $owner->id,
            'editor_id' => $owner->id,
            'created_by' => $owner->id,
            'address' => $address,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ShootFile>
     */
    private function files(Shoot $shoot, User $owner, int $count, string $media)
    {
        $video = $media === 'video';
        $extension = $video ? 'mp4' : 'jpg';

        return collect(range(1, $count))->map(fn (int $index) => ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "p44-{$shoot->id}-{$index}.{$extension}",
            'stored_filename' => "p44-{$shoot->id}-{$index}.{$extension}",
            'path' => "shoots/{$shoot->id}/p44-{$index}.{$extension}",
            'storage_path' => "shoots/{$shoot->id}/p44-{$index}.{$extension}",
            'file_type' => $video ? 'video/mp4' : 'image/jpeg',
            'mime_type' => $video ? 'video/mp4' : 'image/jpeg',
            'file_size' => 100,
            'uploaded_by' => $owner->id,
            'media_type' => $video ? 'video' : 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]));
    }
}
