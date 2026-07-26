<?php

namespace Tests\Feature;

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
 * Feature: ai-editing-studio-revamp, Property 38: Invalid batch and workflow submissions create no jobs.
 *
 * For every generated invalid submission to `POST /api/studio/projects` — zero
 * selected files, more than 100 selected files, media unsupported by the selected
 * Workflow, missing required configuration, or any other invalid field — the
 * Studio_System must reject the submission, identify the invalid field, and create
 * no Project, ProjectMedia, or AI_Job record (and dispatch no processing job).
 *
 * **Validates: Requirements 13.8, 13.13**
 *
 * PHPUnit has no PBT library configured, so a fixed seed drives 22 reproducible
 * cases — one per invalidity category — with randomized parameters
 * (workflow, file counts, offending values) inside each category. Field
 * identification is asserted against the invalid field the payload actually
 * violates, and the "no records" invariant is re-checked after every single case so
 * the first offending submission is reported as the counterexample.
 *
 * @group ai-editing-studio-revamp
 */
class StudioInvalidSubmissionNoJobsPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/projects';
    private const ITERATIONS = 22;
    private const SEED = 20260801;
    private const TEAM = 812;

    /** Every invalidity category the generator must cover. */
    private const CATEGORIES = [
        'zeroFileIds',
        'zeroMediaRefs',
        'tooManyFileIds',
        'tooManyReelFileIds',
        'unsupportedVideoWorkflowMedia',
        'unsupportedImageWorkflowMedia',
        'unsupportedUploadExtension',
        'listingVideoTooFewFiles',
        'missingShootId',
        'missingMediaRefs',
        'missingRequestId',
        'missingSourceType',
        'invalidWorkflowIdType',
        'invalidSourceType',
        'invalidProvider',
        'invalidTargetSeconds',
        'invalidBracketSize',
        'bracketGroupingMismatch',
        'nonIntegerFileId',
        'duplicateFileIds',
        'invalidTemplateId',
        'nameTooLong',
    ];

    private const IMAGE_WORKFLOWS = ['photo-enhancement', 'twilight', 'batch-ai-jobs'];

    private const ALL_WORKFLOWS = [
        'photo-enhancement', 'twilight', 'video-cleanup',
        'listing-video', 'reel-generator', 'batch-ai-jobs',
    ];

    private User $user;

    /** @var list<int> */
    private array $imageFileIds = [];

    private int $videoFileId = 0;

    private int $shootId = 0;

    private string $pdfRef = '';

    private string $jpgRef = '';

    public function test_property_38_invalid_batch_and_workflow_submissions_create_no_jobs(): void
    {
        Queue::fake();
        Storage::fake('public');
        config()->set('studio_uploads.disk', 'public');
        $this->seedAuthorizedSources();

        mt_srand(self::SEED);
        $coverage = array_fill_keys(self::CATEGORIES, false);

        for ($case = 0; $case < self::ITERATIONS; $case++) {
            $category = self::CATEGORIES[$case % count(self::CATEGORIES)];
            $coverage[$category] = true;
            $spec = $this->generateCase($case, $category);

            $response = $this->postJson(self::URL, $spec['payload']);
            $errors = (array) $response->json('errors');

            $counterexample = sprintf(
                "Property 38 counterexample: seed=%d case=%d category=%s expectedField=%s\npayload=%s\nstatus=%d\nbody=%s\nrecords=%s",
                self::SEED,
                $case,
                $category,
                $spec['field'],
                json_encode($spec['payload'], JSON_THROW_ON_ERROR),
                $response->status(),
                json_encode($response->json(), JSON_THROW_ON_ERROR),
                json_encode($this->recordCounts(), JSON_THROW_ON_ERROR)
            );

            // The submission is rejected as a validation failure.
            $this->assertSame(422, $response->status(), $counterexample);
            $this->assertNotSame([], $errors, $counterexample);

            // The rejection identifies the invalid field.
            $this->assertTrue(
                $this->identifiesField(array_keys($errors), $spec['field']),
                $counterexample
            );

            // No Project, ProjectMedia, or AI_Job record exists for any invalid submission.
            $this->assertSame(
                ['projects' => 0, 'projectMedia' => 0, 'editingJobs' => 0, 'listingVideoJobs' => 0, 'reelJobs' => 0],
                $this->recordCounts(),
                $counterexample
            );
            Queue::assertNothingPushed();
        }

        foreach ($coverage as $category => $seen) {
            $this->assertTrue($seen, "Generator did not cover {$category}.");
        }
    }

    /** @return array{payload: array<string, mixed>, field: string} */
    private function generateCase(int $case, string $category): array
    {
        $requestId = "invalid-{$case}";

        return match ($category) {
            'zeroFileIds' => [
                'payload' => $this->shootPayload($requestId, self::ALL_WORKFLOWS[$case % 6], []),
                'field' => 'file_ids',
            ],
            'zeroMediaRefs' => [
                'payload' => $this->uploadPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), []),
                'field' => 'media_refs',
            ],
            'tooManyFileIds' => [
                'payload' => $this->shootPayload(
                    $requestId,
                    $this->pick(self::IMAGE_WORKFLOWS),
                    $this->syntheticIds(mt_rand(101, 108))
                ),
                'field' => 'file_ids',
            ],
            'tooManyReelFileIds' => [
                'payload' => $this->shootPayload($requestId, 'reel-generator', $this->syntheticIds(mt_rand(21, 26))),
                'field' => 'file_ids',
            ],
            'unsupportedVideoWorkflowMedia' => [
                'payload' => $this->shootPayload($requestId, 'video-cleanup', $this->images(mt_rand(1, 3))),
                'field' => 'file_ids',
            ],
            'unsupportedImageWorkflowMedia' => [
                'payload' => mt_rand(0, 1) === 0
                    ? $this->shootPayload($requestId, 'reel-generator', [...$this->images(mt_rand(0, 2)), $this->videoFileId])
                    : $this->shootPayload($requestId, 'listing-video', [...$this->images(5), $this->videoFileId]),
                'field' => 'file_ids',
            ],
            'unsupportedUploadExtension' => [
                'payload' => mt_rand(0, 1) === 0
                    ? $this->uploadPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), [$this->pdfRef])
                    : $this->uploadPayload($requestId, 'video-cleanup', [$this->jpgRef]),
                'field' => 'media_refs',
            ],
            'listingVideoTooFewFiles' => [
                'payload' => $this->shootPayload($requestId, 'listing-video', $this->images(mt_rand(1, 5))),
                'field' => 'file_ids',
            ],
            'missingShootId' => [
                'payload' => $this->withoutKeys(
                    $this->shootPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), $this->images(1)),
                    ['shoot_id']
                ),
                'field' => 'shoot_id',
            ],
            'missingMediaRefs' => [
                'payload' => $this->withoutKeys(
                    $this->uploadPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), [$this->jpgRef]),
                    ['media_refs']
                ),
                'field' => 'media_refs',
            ],
            'missingRequestId' => [
                'payload' => $this->withoutKeys(
                    $this->shootPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), $this->images(1)),
                    ['request_id']
                ),
                'field' => 'request_id',
            ],
            'missingSourceType' => [
                'payload' => $this->withoutKeys(
                    $this->shootPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), $this->images(1)),
                    ['source_type']
                ),
                'field' => 'source_type',
            ],
            'invalidWorkflowIdType' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, 'photo-enhancement', $this->images(1)),
                    ['workflow_id' => mt_rand(1, 9)]
                ),
                'field' => 'workflow_id',
            ],
            'invalidSourceType' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), $this->images(1)),
                    ['source_type' => $this->pick(['dropbox', 'shoots', 'upload-batch', 'library'])]
                ),
                'field' => 'source_type',
            ],
            'invalidProvider' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, 'photo-enhancement', $this->images(1)),
                    ['provider' => $this->pick(['dreamstudio', 'openai', 'autoenhancer', 'FAL-2'])]
                ),
                'field' => 'provider',
            ],
            'invalidTargetSeconds' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, 'listing-video', $this->images(6)),
                    ['target_seconds' => $this->pick([0, 15, 29, 31, 37, 50, 60])]
                ),
                'field' => 'target_seconds',
            ],
            'invalidBracketSize' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, 'twilight', $this->images(mt_rand(3, 6))),
                    ['bracket_size' => $this->pick([1, 2, 4, 6, 10])]
                ),
                'field' => 'bracket_size',
            ],
            'bracketGroupingMismatch' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, 'twilight', $this->images(mt_rand(3, 4))),
                    ['bracket_size' => 5]
                ),
                'field' => 'bracket_size',
            ],
            'nonIntegerFileId' => [
                'payload' => $this->shootPayload(
                    $requestId,
                    $this->pick(self::IMAGE_WORKFLOWS),
                    [...$this->images(mt_rand(0, 2)), $this->pick(['not-an-id', 'abc', '12x'])]
                ),
                'field' => 'file_ids',
            ],
            'duplicateFileIds' => [
                'payload' => $this->shootPayload(
                    $requestId,
                    $this->pick(self::IMAGE_WORKFLOWS),
                    array_fill(0, mt_rand(2, 3), $this->imageFileIds[0])
                ),
                'field' => 'file_ids',
            ],
            'invalidTemplateId' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, 'photo-enhancement', $this->images(1)),
                    ['template_id' => $this->pick(['not-a-uuid', '12345', 'abc-def-ghi'])]
                ),
                'field' => 'template_id',
            ],
            'nameTooLong' => [
                'payload' => array_merge(
                    $this->shootPayload($requestId, $this->pick(self::IMAGE_WORKFLOWS), $this->images(1)),
                    ['name' => str_repeat('a', mt_rand(256, 400))]
                ),
                'field' => 'name',
            ],
        };
    }

    /**
     * @param  list<mixed>  $fileIds
     * @return array<string, mixed>
     */
    private function shootPayload(string $requestId, string $workflow, array $fileIds): array
    {
        return [
            'request_id' => $requestId,
            'workflow_id' => $workflow,
            'source_type' => 'shoot',
            'shoot_id' => $this->shootId,
            'file_ids' => $fileIds,
            'workflow_config' => [],
        ];
    }

    /**
     * @param  list<string>  $mediaRefs
     * @return array<string, mixed>
     */
    private function uploadPayload(string $requestId, string $workflow, array $mediaRefs): array
    {
        return [
            'request_id' => $requestId,
            'workflow_id' => $workflow,
            'source_type' => 'upload',
            'media_refs' => $mediaRefs,
            'workflow_config' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function withoutKeys(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /** @return list<int> */
    private function images(int $count): array
    {
        return array_slice($this->imageFileIds, 0, $count);
    }

    /** @return list<int> */
    private function syntheticIds(int $count): array
    {
        return range(500000, 500000 + $count - 1);
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return TValue
     */
    private function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    /**
     * A rejection identifies the field when it reports that field or one of its members.
     *
     * @param  list<string>  $errorKeys
     */
    private function identifiesField(array $errorKeys, string $field): bool
    {
        foreach ($errorKeys as $key) {
            if ($key === $field || str_starts_with($key, $field.'.')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> */
    private function recordCounts(): array
    {
        return [
            'projects' => Project::query()->count(),
            'projectMedia' => ProjectMedia::query()->count(),
            'editingJobs' => AiEditingJob::query()->count(),
            'listingVideoJobs' => AiListingVideoJob::query()->count(),
            'reelJobs' => AiReelJob::query()->count(),
        ];
    }

    private function seedAuthorizedSources(): void
    {
        $this->user = User::factory()->create([
            'role' => 'admin',
            'metadata' => ['team_id' => self::TEAM],
        ]);
        Sanctum::actingAs($this->user);

        $shoot = Shoot::factory()->create([
            'created_by' => $this->user->id,
            'address' => '19 Property Row',
        ]);
        $this->shootId = (int) $shoot->id;

        $this->imageFileIds = collect(range(1, 10))->map(fn (int $index): int => (int) ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "photo-{$index}.jpg",
            'stored_filename' => "photo-{$index}.jpg",
            'path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'storage_path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'uploaded_by' => $this->user->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ])->id)->all();

        $this->videoFileId = (int) ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'walkthrough.mp4',
            'stored_filename' => 'walkthrough.mp4',
            'path' => "shoots/{$shoot->id}/walkthrough.mp4",
            'storage_path' => "shoots/{$shoot->id}/walkthrough.mp4",
            'file_type' => 'video/mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 4096,
            'uploaded_by' => $this->user->id,
            'media_type' => 'video',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ])->id;

        $prefix = 'studio/uploads/'.self::TEAM.'/'.$this->user->id.'/';
        $this->pdfRef = $prefix.'brochure.pdf';
        $this->jpgRef = $prefix.'living-room.jpg';
        Storage::disk('public')->put($this->pdfRef, 'not-an-image');
        Storage::disk('public')->put($this->jpgRef, 'image-bytes');
    }
}
