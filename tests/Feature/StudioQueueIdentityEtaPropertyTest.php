<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 42: Queue records have globally unique ids with ETA metadata
 *
 * **Validates: Requirements 16.3, 16.5**
 *
 * A deterministic generator produces 30 cases (15 photo, 15 video) by rotating
 * rather than fully crossing its dimensions, so every active and terminal
 * status, every absent/mid/clamped progress source, and every
 * absent/zero/elapsed start time is still exercised. Photo and video jobs are
 * created so their primary keys collide, which is the only way an unnamespaced
 * queue identifier can lose global uniqueness.
 */
class StudioQueueIdentityEtaPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const CASE_COUNT = 30;

    private const CASES_PER_JOB_TYPE = 15;

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

    public function test_property_42_queue_records_have_globally_unique_ids_with_eta_metadata(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['team_id' => 42_016],
        ]);
        $shoot = Shoot::factory()->create(['address' => '42 Identity Row']);

        $cases = $this->casesGenerator();
        $this->assertCount(self::CASE_COUNT, $cases);
        $this->assertSame(2 * self::CASES_PER_JOB_TYPE, count($cases));
        $this->assertCoversEveryDimension($cases);

        $photoKeys = [];
        $videoKeys = [];
        $expected = [];

        // Photo jobs are created first, then video jobs, so both tables issue the
        // same primary keys and any collision-prone identifier scheme is exercised.
        foreach (['photo', 'video'] as $jobType) {
            foreach ($cases as $index => $case) {
                if ($case['jobType'] !== $jobType) {
                    continue;
                }

                $startedAt = $case['startedSecondsAgo'] === null
                    ? null
                    : now()->subSeconds($case['startedSecondsAgo']);

                $job = $jobType === 'photo'
                    ? $this->photoJob($editor, $shoot, $case['attributes'] + [
                        'status' => $case['status'],
                        'started_at' => $startedAt,
                    ])
                    : $this->videoJob($editor, $shoot, $case['attributes'] + [
                        'status' => $case['status'],
                        'started_at' => $startedAt,
                    ]);

                if ($jobType === 'photo') {
                    $photoKeys[] = (int) $job->getKey();
                } else {
                    $videoKeys[] = (int) $job->getKey();
                }

                $expected[$jobType . '-' . $job->getKey()] = [
                    'jobType' => $jobType,
                    'aiJobId' => (string) $job->getKey(),
                    'context' => sprintf(
                        'case=%d, type=%s, status=%s, progress=%s, startedSecondsAgo=%s',
                        $index,
                        $jobType,
                        $case['status'],
                        $case['progressSpec'],
                        $case['startedSecondsAgo'] === null ? 'null' : $case['startedSecondsAgo']
                    ),
                ];
            }
        }

        $collidingKeys = array_intersect($photoKeys, $videoKeys);
        $this->assertNotEmpty(
            $collidingKeys,
            'Generator must produce colliding photo/video primary keys to exercise Property 42.'
        );

        Sanctum::actingAs($editor);
        $response = $this->getJson('/api/studio/queue')->assertOk();
        $records = $response->json('data');
        $serverCalculatedAt = $response->json('meta.calculatedAt');

        $this->assertCount(self::CASE_COUNT, $records);

        $ids = array_column($records, 'id');
        $this->assertSameSize(
            $ids,
            array_unique($ids),
            'Queue identifiers were not globally unique across photo and video jobs.'
        );
        $this->assertEqualsCanonicalizing(array_keys($expected), $ids);

        $etasPresent = 0;
        $etasAbsent = 0;

        foreach ($records as $record) {
            $id = $record['id'];
            $case = $expected[$id];
            $context = $case['context'];

            $this->assertMatchesRegularExpression(
                '/^(photo|video)-[1-9][0-9]*$/',
                $id,
                'Queue identifier was not namespaced (' . $context . ').'
            );
            $this->assertSame($case['jobType'], $record['jobType'], $context);
            $this->assertSame(
                $case['aiJobId'],
                $record['aiJobId'],
                'Queue record did not expose its associated AI_Job identifier (' . $context . ').'
            );
            $this->assertSame(
                $record['jobType'] . '-' . $record['aiJobId'],
                $id,
                'Queue identifier did not namespace its AI_Job identifier (' . $context . ').'
            );

            $eta = $record['eta'];
            if ($eta === null) {
                $etasAbsent++;
                continue;
            }

            $etasPresent++;
            $this->assertIsArray($eta, $context);
            $this->assertEqualsCanonicalizing(
                ['estimateSeconds', 'calculatedAt'],
                array_keys($eta),
                'ETA payload must carry only the estimate and its calculation time (' . $context . ').'
            );
            $this->assertTrue(
                is_int($eta['estimateSeconds']) || is_float($eta['estimateSeconds']),
                'ETA estimate must be numeric (' . $context . ').'
            );
            $this->assertGreaterThanOrEqual(0, $eta['estimateSeconds'], $context);
            $this->assertSame(
                $serverCalculatedAt,
                $eta['calculatedAt'],
                'ETA calculation time must be the server time of the request (' . $context . ').'
            );
            $this->assertTrue(
                Carbon::hasFormat($eta['calculatedAt'], 'Y-m-d\TH:i:s.u\Z'),
                'ETA calculation time must be a server timestamp (' . $context . ').'
            );
        }

        $this->assertGreaterThan(0, $etasPresent, 'Generator produced no ETA-bearing queue records.');
        $this->assertGreaterThan(0, $etasAbsent, 'Generator produced no ETA-free queue records.');
    }

    /**
     * @return array<int, array{
     *     jobType: string,
     *     status: string,
     *     progressSpec: string,
     *     attributes: array<string, mixed>,
     *     startedSecondsAgo: int|null
     * }>
     */
    private function casesGenerator(): array
    {
        $statuses = [
            'photo' => [
                AiEditingJob::STATUS_PENDING,
                AiEditingJob::STATUS_PROCESSING,
                AiEditingJob::STATUS_COMPLETED,
                AiEditingJob::STATUS_FAILED,
                AiEditingJob::STATUS_CANCELLED,
            ],
            'video' => [
                AiListingVideoJob::STATUS_QUEUED,
                AiListingVideoJob::STATUS_PROCESSING,
                AiListingVideoJob::STATUS_STITCHING,
                AiListingVideoJob::STATUS_COMPLETED,
                AiListingVideoJob::STATUS_FAILED,
                AiListingVideoJob::STATUS_CANCELLED,
            ],
        ];

        $progressSpecs = [
            'photo' => [
                'absent' => ['provider_payload' => ['state' => 'waiting']],
                'mid' => ['provider_result' => ['data' => ['progress_percent' => 25]]],
                'above-range' => ['provider_payload' => ['progress' => 140]],
                'below-range' => ['provider_payload' => ['progress' => -20]],
            ],
            'video' => [
                'zero-clip' => ['total_clips' => 0, 'completed_clips' => 0],
                'mid' => ['total_clips' => 4, 'completed_clips' => 1],
                'full' => ['total_clips' => 4, 'completed_clips' => 4],
            ],
        ];

        $startTimes = [null, 0, 60];
        $cases = [];

        // Dimensions are rotated instead of fully crossed: each job type emits
        // CASES_PER_JOB_TYPE cases whose status, progress source, and start-time
        // indexes advance at different rates. The rotation is longer than every
        // dimension, so each status, progress source, and start time is still
        // visited, and the start-time offset keeps it from locking in step with
        // the progress source.
        foreach (['photo', 'video'] as $jobType) {
            $typeStatuses = $statuses[$jobType];
            $typeProgress = $progressSpecs[$jobType];
            $progressKeys = array_keys($typeProgress);

            for ($index = 0; $index < self::CASES_PER_JOB_TYPE; $index++) {
                $status = $typeStatuses[$index % count($typeStatuses)];
                $progressSpec = $progressKeys[$index % count($progressKeys)];
                $attributes = $typeProgress[$progressSpec];
                $startedSecondsAgo = $startTimes[
                    ($index + intdiv($index, count($startTimes))) % count($startTimes)
                ];

                $cases[] = compact('jobType', 'status', 'progressSpec', 'attributes', 'startedSecondsAgo');
            }
        }

        return $cases;
    }

    /**
     * Guards that the rotated generator still visits every job type, status,
     * progress source, and start-time variant.
     *
     * @param array<int, array<string, mixed>> $cases
     */
    private function assertCoversEveryDimension(array $cases): void
    {
        $seen = [
            'jobType' => [],
            'status' => [],
            'progressSpec' => [],
            'startedSecondsAgo' => [],
        ];

        foreach ($cases as $case) {
            $seen['jobType'][$case['jobType']] = true;
            $seen['status'][$case['jobType'] . ':' . $case['status']] = true;
            $seen['progressSpec'][$case['jobType'] . ':' . $case['progressSpec']] = true;
            $seen['startedSecondsAgo'][$case['startedSecondsAgo'] === null ? 'null' : 's' . $case['startedSecondsAgo']] = true;
        }

        $this->assertEqualsCanonicalizing(['photo', 'video'], array_keys($seen['jobType']));
        $this->assertEqualsCanonicalizing([
            'photo:' . AiEditingJob::STATUS_PENDING,
            'photo:' . AiEditingJob::STATUS_PROCESSING,
            'photo:' . AiEditingJob::STATUS_COMPLETED,
            'photo:' . AiEditingJob::STATUS_FAILED,
            'photo:' . AiEditingJob::STATUS_CANCELLED,
            'video:' . AiListingVideoJob::STATUS_QUEUED,
            'video:' . AiListingVideoJob::STATUS_PROCESSING,
            'video:' . AiListingVideoJob::STATUS_STITCHING,
            'video:' . AiListingVideoJob::STATUS_COMPLETED,
            'video:' . AiListingVideoJob::STATUS_FAILED,
            'video:' . AiListingVideoJob::STATUS_CANCELLED,
        ], array_keys($seen['status']), 'Rotation skipped a photo or video status.');
        $this->assertEqualsCanonicalizing([
            'photo:absent',
            'photo:mid',
            'photo:above-range',
            'photo:below-range',
            'video:zero-clip',
            'video:mid',
            'video:full',
        ], array_keys($seen['progressSpec']), 'Rotation skipped a progress source.');
        $this->assertEqualsCanonicalizing(
            ['null', 's0', 's60'],
            array_keys($seen['startedSecondsAgo']),
            'Rotation skipped a start-time variant.'
        );
    }

    private function photoJob(User $owner, Shoot $shoot, array $overrides): AiEditingJob
    {
        return AiEditingJob::create(array_merge([
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'status' => AiEditingJob::STATUS_PENDING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => '/media/property-42-source.jpg',
        ], $overrides));
    }

    private function videoJob(User $owner, Shoot $shoot, array $overrides): AiListingVideoJob
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
