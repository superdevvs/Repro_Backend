<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 19: Queue membership follows status and retention
 *
 * **Validates: Requirements 7.2, 7.14**
 *
 * A deterministic generator builds 31 cases that cover both authorized queue
 * scopes, every photo/video active status, every terminal status, both
 * completion/update time sources, and timestamps immediately inside, at, and
 * outside retention. Scope, job type, and status are rotated across the
 * retention/timestamp crossings instead of fully duplicated.
 */
class StudioQueueMembershipRetentionPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const CASE_COUNT = 31;
    private const RETENTION_HOURS = 24;

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
    public function test_property_19_queue_membership_follows_status_and_retention(): void
    {
        $teamId = 19_714;
        $editor = $this->teamUser('editor', $teamId);
        $admin = $this->teamUser('admin', $teamId);
        $teamPeer = $this->teamUser('editor', $teamId);
        $shoot = Shoot::factory()->create();
        $cases = $this->casesGenerator();

        $this->assertCount(self::CASE_COUNT, $cases);
        $this->assertGreaterThanOrEqual(30, count($cases));

        foreach ($cases as $index => &$case) {
            $owner = $case['scope'] === 'editor-self' ? $editor : $teamPeer;
            $eventAt = now()->subHours(self::RETENTION_HOURS)
                ->addSeconds($case['boundarySeconds']);
            $attributes = [
                'status' => $case['status'],
                'completed_at' => $case['timestampSource'] === 'completed_at' ? $eventAt : null,
            ];

            $job = $case['jobType'] === 'photo'
                ? $this->photoJob($owner, $shoot, $attributes)
                : $this->videoJob($owner, $shoot, $attributes);

            $updatedAt = $case['timestampSource'] === 'updated_at' ? $eventAt : now();
            DB::table($job->getTable())->where('id', $job->getKey())->update([
                'created_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ]);

            $case['queueId'] = $case['jobType'] . '-' . $job->getKey();
            $case['expectedMember'] = $case['kind'] === 'active'
                || $eventAt->greaterThanOrEqualTo(now()->subHours(self::RETENTION_HOURS));
            $case['context'] = sprintf(
                'case=%d, scope=%s, type=%s, status=%s, source=%s, boundarySeconds=%d',
                $index,
                $case['scope'],
                $case['jobType'],
                $case['status'],
                $case['timestampSource'],
                $case['boundarySeconds']
            );
        }
        unset($case);

        Sanctum::actingAs($editor);
        $editorQueue = collect($this->getJson('/api/studio/queue')->assertOk()->json('data'))
            ->keyBy('id');

        Sanctum::actingAs($admin);
        $teamQueue = collect($this->getJson('/api/studio/queue')->assertOk()->json('data'))
            ->keyBy('id');

        foreach ($cases as $case) {
            $queue = $case['scope'] === 'editor-self' ? $editorQueue : $teamQueue;
            $this->assertSame(
                $case['expectedMember'],
                $queue->has($case['queueId']),
                'Queue membership violated Property 19 (' . $case['context'] . ').'
            );
        }
    }

    /**
     * @return array<int, array{
     *     scope: string,
     *     jobType: string,
     *     kind: string,
     *     status: string,
     *     timestampSource: string,
     *     boundarySeconds: int
     * }>
     */
    private function casesGenerator(): array
    {
        $cases = [];
        $boundaries = [-1, 0, 1];
        $scopes = ['editor-self', 'privileged-team'];
        $sources = ['completed_at', 'updated_at'];
        $activeStatuses = [
            'photo' => [AiEditingJob::STATUS_PENDING, AiEditingJob::STATUS_PROCESSING],
            'video' => [
                AiListingVideoJob::STATUS_QUEUED,
                AiListingVideoJob::STATUS_PROCESSING,
                AiListingVideoJob::STATUS_STITCHING,
            ],
        ];
        $terminalStatuses = [
            AiEditingJob::STATUS_COMPLETED,
            AiEditingJob::STATUS_FAILED,
            AiEditingJob::STATUS_CANCELLED,
        ];

        // Active statuses (10): every photo/video active status under both scopes,
        // rotating the retention boundary because active jobs ignore retention.
        $rotation = 0;
        foreach ($scopes as $scope) {
            foreach ($activeStatuses as $jobType => $statuses) {
                foreach ($statuses as $status) {
                    $cases[] = [
                        'scope' => $scope,
                        'jobType' => $jobType,
                        'status' => $status,
                        'kind' => 'active',
                        'timestampSource' => 'updated_at',
                        'boundarySeconds' => $boundaries[$rotation % count($boundaries)],
                    ];
                    $rotation++;
                }
            }
        }

        // Terminal statuses (12): full crossing of job type, timestamp source, and
        // retention boundary, rotating terminal status and scope.
        $rotation = 0;
        foreach (['photo', 'video'] as $jobType) {
            foreach ($sources as $timestampSource) {
                foreach ($boundaries as $boundarySeconds) {
                    $cases[] = [
                        'scope' => $scopes[$rotation % count($scopes)],
                        'jobType' => $jobType,
                        'status' => $terminalStatuses[$rotation % count($terminalStatuses)],
                        'kind' => 'terminal',
                        'timestampSource' => $timestampSource,
                        'boundarySeconds' => $boundarySeconds,
                    ];
                    $rotation++;
                }
            }
        }

        // Terminal statuses (9): every terminal status against every retention
        // boundary, rotating job type, timestamp source, and scope.
        $rotation = 0;
        foreach ($terminalStatuses as $status) {
            foreach ($boundaries as $boundarySeconds) {
                $cases[] = [
                    'scope' => $scopes[($rotation + 1) % count($scopes)],
                    'jobType' => $rotation % 2 === 0 ? 'video' : 'photo',
                    'status' => $status,
                    'kind' => 'terminal',
                    'timestampSource' => $sources[($rotation + 1) % count($sources)],
                    'boundarySeconds' => $boundarySeconds,
                ];
                $rotation++;
            }
        }

        return $cases;
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function photoJob(User $owner, Shoot $shoot, array $overrides): AiEditingJob
    {
        return AiEditingJob::create(array_merge([
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'status' => AiEditingJob::STATUS_PENDING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => '/media/property-19-source.jpg',
        ], $overrides));
    }

    private function videoJob(User $owner, Shoot $shoot, array $overrides): AiListingVideoJob
    {
        return AiListingVideoJob::create(array_merge([
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
}
