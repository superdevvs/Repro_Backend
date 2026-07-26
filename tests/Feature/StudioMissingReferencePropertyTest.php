<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 43: Missing references produce not-found without dependents
 *
 * **Validates: Requirements 16.9**
 *
 * A deterministic generator covers all 35 cases: every deep-link record type
 * (Project, Shoot, Template, Workflow, AI_Job) with missing and malformed
 * identities, rotated across the four destinations so each destination is
 * exercised, plus a representative Project-creation matrix that keeps at least
 * one case per missing-reference kind (missing Shoot, missing Shoot file,
 * missing Template, missing uploaded media, unknown Workflow). Each case must
 * yield a not-found rejection, leave every dependent record table structurally
 * unchanged, and expose no restricted data.
 */
class StudioMissingReferencePropertyTest extends TestCase
{
    use RefreshDatabase;

    private const CASE_COUNT = 35;

    private const TEAM_ID = 4_316;

    private const OUTSIDE_TEAM_ID = 4_399;

    private const DEEP_LINK_URL = '/api/studio/deep-links/resolve';

    private const PROJECT_URL = '/api/studio/projects';

    /** Identity-bearing response fields that a not-found rejection must never carry. */
    private const IDENTITY_KEYS = [
        'data', 'record', 'recordType', 'recordId', 'projectId', 'aiJobId',
        'aiJobIds', 'jobs', 'deepLink', 'shootId', 'templateId', 'version',
    ];

    private const SENTINELS = [
        'RESTRICTED-SENTINEL-PROJECT',
        'RESTRICTED-SENTINEL-ADDRESS',
        'RESTRICTED-SENTINEL-TEMPLATE',
    ];

    private const WORKFLOWS = [
        'photo-enhancement' => ['media' => 'image', 'minimum' => 1],
        'twilight' => ['media' => 'image', 'minimum' => 1],
        'video-cleanup' => ['media' => 'video', 'minimum' => 1],
        'listing-video' => ['media' => 'image', 'minimum' => 6],
        'reel-generator' => ['media' => 'image', 'minimum' => 1],
        'batch-ai-jobs' => ['media' => 'image', 'minimum' => 1],
    ];

    private const MISSING_UUID = '3f9c0b4e-0000-4c1a-8d55-000000000001';

    private const MISSING_TEMPLATE_UUID = '3f9c0b4e-0000-4c1a-8d55-000000000002';

    public function test_property_43_missing_references_produce_not_found_without_dependents(): void
    {
        Queue::fake();
        Storage::fake('public');
        config()->set('studio_uploads.disk', 'public');

        $actor = $this->teamUser('admin', self::TEAM_ID);
        $this->seedRestrictedRecords();
        $imageShoot = $this->shoot($actor, '18 Authorized Image Way');
        $videoShoot = $this->shoot($actor, '22 Authorized Video Way');
        $imageFiles = $this->files($imageShoot, $actor, 10, 'image')->pluck('id')->all();
        $videoFiles = $this->files($videoShoot, $actor, 6, 'video')->pluck('id')->all();

        $cases = $this->casesGenerator(
            $actor->id,
            ['image' => $imageShoot->id, 'video' => $videoShoot->id],
            ['image' => $imageFiles, 'video' => $videoFiles]
        );
        $this->assertCount(self::CASE_COUNT, $cases);
        $this->assertGreaterThanOrEqual(30, count($cases));

        Sanctum::actingAs($actor);
        $baseline = $this->dependentCounts();

        foreach ($cases as $index => $case) {
            $context = sprintf('case=%d, %s', $index, $case['context']);
            $response = $this->postJson($case['url'], $case['payload']);

            $response->assertNotFound();
            $this->assertNoIdentityLeak($response, $context);
            $this->assertSame(
                $baseline,
                $this->dependentCounts(),
                'Missing reference created dependent records, violating Property 43 (' . $context . ').'
            );
        }

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, int>  $shootIds
     * @param  array<string, array<int, int>>  $fileIds
     * @return array<int, array{url: string, payload: array<string, mixed>, context: string}>
     */
    private function casesGenerator(int $userId, array $shootIds, array $fileIds): array
    {
        return array_merge(
            $this->deepLinkCases(),
            $this->projectCreationCases($userId, $shootIds, $fileIds)
        );
    }

    /**
     * @return array<int, array{url: string, payload: array<string, mixed>, context: string}>
     */
    private function deepLinkCases(): array
    {
        $references = [
            ['project', self::MISSING_UUID],
            ['project', '9f2b7a10-1111-4c1a-8d55-2222aaaa3333'],
            ['project', 'not-a-uuid'],
            ['project', '999999'],
            ['shoot', '999999'],
            ['shoot', '0'],
            ['shoot', '-1'],
            ['shoot', 'not-a-number'],
            ['shoot', '12abc'],
            ['template', self::MISSING_TEMPLATE_UUID],
            ['template', 'not-a-uuid'],
            ['workflow', 'unknown-workflow'],
            ['workflow', 'photo enhancement'],
            ['workflow', 'Twilight'],
            ['ai_job', 'photo-999999'],
            ['ai_job', 'video-999999'],
            ['ai_job', 'reel-999999'],
            ['ai_job', 'photo-0'],
            ['ai_job', 'audio-1'],
            ['ai_job', 'not-a-number'],
            ['ai_job', 'photo-1x'],
        ];
        // Destinations rotate across the reference variants instead of crossing all
        // four, so every record type, malformed identity, and destination stays
        // reachable without paying for the full 21 x 4 matrix.
        $destinations = ['command-center', 'projects', 'queue', 'templates'];
        $cases = [];

        foreach (array_values($references) as $index => [$recordType, $recordId]) {
            $destination = $destinations[$index % count($destinations)];
            $cases[] = [
                'url' => self::DEEP_LINK_URL,
                'payload' => compact('destination', 'recordType', 'recordId'),
                'context' => sprintf(
                    'deep-link destination=%s recordType=%s recordId=%s',
                    $destination,
                    $recordType,
                    $recordId
                ),
            ];
        }

        return $cases;
    }

    /**
     * @param  array<string, int>  $shootIds
     * @param  array<string, array<int, int>>  $fileIds
     * @return array<int, array{url: string, payload: array<string, mixed>, context: string}>
     */
    private function projectCreationCases(int $userId, array $shootIds, array $fileIds): array
    {
        $cases = [];
        $missingFileId = 987_654;
        $uploadPrefix = 'studio/uploads/' . self::TEAM_ID . '/' . $userId . '/';

        // Missing-reference kinds rotate across a representative subset of workflows
        // rather than crossing every workflow with every kind. Each kind keeps at
        // least one case, and both media shapes plus the multi-file minimum
        // (listing-video) stay covered.
        $matrix = [
            'photo-enhancement' => ['shoot', 'template'],
            'twilight' => ['shoot-file'],
            'video-cleanup' => ['uploaded-media', 'shoot-file'],
            'listing-video' => ['shoot-file', 'uploaded-media'],
            'reel-generator' => ['template'],
            'batch-ai-jobs' => ['shoot'],
        ];

        foreach ($matrix as $workflow => $kinds) {
            $shape = self::WORKFLOWS[$workflow];
            $media = $shape['media'];
            $minimum = $shape['minimum'];
            $realFiles = array_slice($fileIds[$media], 0, $minimum);
            $extension = $media === 'video' ? 'mp4' : 'jpg';

            foreach ($kinds as $kind) {
                $payload = match ($kind) {
                    'shoot' => [
                        'request_id' => "p43-missing-shoot-{$workflow}",
                        'workflow_id' => $workflow,
                        'source_type' => 'shoot',
                        'shoot_id' => 999_999,
                        'file_ids' => range(900_001, 900_000 + $minimum),
                        'workflow_config' => [],
                    ],
                    'shoot-file' => [
                        'request_id' => "p43-missing-file-{$workflow}",
                        'workflow_id' => $workflow,
                        'source_type' => 'shoot',
                        'shoot_id' => $shootIds[$media],
                        'file_ids' => array_merge([$missingFileId], array_slice($realFiles, 0, $minimum - 1)),
                        'workflow_config' => [],
                    ],
                    'template' => [
                        'request_id' => "p43-missing-template-{$workflow}",
                        'workflow_id' => $workflow,
                        'source_type' => 'shoot',
                        'shoot_id' => $shootIds[$media],
                        'file_ids' => $realFiles,
                        'template_id' => self::MISSING_TEMPLATE_UUID,
                        'workflow_config' => [],
                    ],
                    'uploaded-media' => [
                        'request_id' => "p43-missing-upload-{$workflow}",
                        'workflow_id' => $workflow,
                        'source_type' => 'upload',
                        'media_refs' => collect(range(1, $minimum))
                            ->map(fn (int $index): string => "{$uploadPrefix}absent-{$index}.{$extension}")
                            ->all(),
                        'workflow_config' => [],
                    ],
                };

                $cases[] = [
                    'url' => self::PROJECT_URL,
                    'payload' => $payload,
                    'context' => "create workflow={$workflow} missing={$kind}",
                ];
            }
        }

        foreach (['missing-workflow', 'photo_enhancement', 'PhotoEnhancement', 'listing video', 'reels'] as $unknown) {
            $cases[] = [
                'url' => self::PROJECT_URL,
                'payload' => [
                    'request_id' => "p43-missing-workflow-{$unknown}",
                    'workflow_id' => $unknown,
                    'source_type' => 'shoot',
                    'shoot_id' => $shootIds['image'],
                    'file_ids' => array_slice($fileIds['image'], 0, 1),
                    'workflow_config' => [],
                ],
                'context' => "create missing=workflow workflowId={$unknown}",
            ];
        }

        return $cases;
    }

    private function assertNoIdentityLeak(TestResponse $response, string $context): void
    {
        $payload = $response->json();
        $this->assertIsArray($payload, 'Not-found response was not a JSON object (' . $context . ').');

        foreach ($this->keysOf($payload) as $key) {
            $this->assertNotContains(
                $key,
                self::IDENTITY_KEYS,
                "Not-found response exposed the identity field '{$key}', violating Property 43 ({$context})."
            );
        }

        $content = (string) $response->getContent();
        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $content,
                'Not-found response exposed restricted record data, violating Property 43 (' . $context . ').'
            );
        }
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int, string>
     */
    private function keysOf(array $payload): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = array_merge($keys, $this->keysOf($value));
            }
        }

        return $keys;
    }

    /**
     * @return array<string, int>
     */
    private function dependentCounts(): array
    {
        return [
            'projects' => Project::query()->count(),
            'projectMedia' => ProjectMedia::query()->count(),
            'photoJobs' => AiEditingJob::query()->count(),
            'listingVideoJobs' => AiListingVideoJob::query()->count(),
            'reelJobs' => AiReelJob::query()->count(),
        ];
    }

    private function seedRestrictedRecords(): void
    {
        $outsider = $this->teamUser('editor', self::OUTSIDE_TEAM_ID);
        $shoot = $this->shoot($outsider, 'RESTRICTED-SENTINEL-ADDRESS');
        Project::query()->create([
            'team_id' => self::OUTSIDE_TEAM_ID,
            'created_by' => $outsider->id,
            'shoot_id' => $shoot->id,
            'name' => 'RESTRICTED-SENTINEL-PROJECT',
            'address' => $shoot->address,
            'source_type' => 'shoot',
            'workflow_id' => 'photo-enhancement',
            'status' => 'draft',
        ]);
        Template::query()->create([
            'team_id' => self::OUTSIDE_TEAM_ID,
            'created_by' => $outsider->id,
            'name' => 'RESTRICTED-SENTINEL-TEMPLATE',
            'workflow_id' => 'photo-enhancement',
            'config' => ['strength' => 50],
        ]);
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
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

        return collect(range(1, $count))->map(fn (int $index) => ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "p43-{$shoot->id}-{$index}." . ($video ? 'mp4' : 'jpg'),
            'stored_filename' => "p43-{$shoot->id}-{$index}." . ($video ? 'mp4' : 'jpg'),
            'path' => "shoots/{$shoot->id}/p43-{$index}." . ($video ? 'mp4' : 'jpg'),
            'storage_path' => "shoots/{$shoot->id}/p43-{$index}." . ($video ? 'mp4' : 'jpg'),
            'file_type' => $video ? 'video/mp4' : 'image/jpeg',
            'mime_type' => $video ? 'video/mp4' : 'image/jpeg',
            'file_size' => 100,
            'uploaded_by' => $owner->id,
            'media_type' => $video ? 'video' : 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]));
    }
}
