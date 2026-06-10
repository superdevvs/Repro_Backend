<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Property-based tests for the Studio Metrics active-queue aggregation.
 *
 * Feature: ai-editing-default-page
 * Property 6: Active queue contains exactly the non-terminal jobs.
 *   For any set of photo and listing-video jobs, the active queue contains a
 *   job if and only if that job's status is non-terminal (photo: `pending` or
 *   `processing`; video: `queued`, `processing`, or `stitching`), with each
 *   such job appearing exactly once.
 *
 * Validates: Requirements 6.2
 *
 * The backend has no dedicated property-testing library, so these tests use a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over randomized combinations of jobs spread across several shoots, drawing
 * from EVERY status (terminal + non-terminal) in both tables. The endpoint
 * namespaces ids by job_type (`photo-{id}` / `video-{id}`) so numeric ids never
 * collide across tables; the test asserts the returned set of namespaced ids
 * equals exactly the set of non-terminal jobs created in that iteration — no
 * terminal job present, no active job missing, and no duplicates. The fixed seed
 * makes any counterexample reproducible.
 *
 * @group ai-editing-default-page
 */
class StudioMetricsActiveQueueTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 120;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251215;

    /** Endpoint under test. */
    private const ACTIVE_QUEUE_URL = '/api/studio/metrics/active-queue';

    /** Number of distinct shoots available to the generator. */
    private const SHOOT_POOL = 5;

    /** Photo (AiEditingJob) statuses the generator draws from (all statuses). */
    private const PHOTO_STATUSES = [
        AiEditingJob::STATUS_PENDING,
        AiEditingJob::STATUS_PROCESSING,
        AiEditingJob::STATUS_COMPLETED,
        AiEditingJob::STATUS_FAILED,
        AiEditingJob::STATUS_CANCELLED,
    ];

    /** Photo statuses that are active / non-terminal. */
    private const PHOTO_ACTIVE_STATUSES = [
        AiEditingJob::STATUS_PENDING,
        AiEditingJob::STATUS_PROCESSING,
    ];

    /** Video (AiListingVideoJob) statuses the generator draws from (all statuses). */
    private const VIDEO_STATUSES = [
        AiListingVideoJob::STATUS_QUEUED,
        AiListingVideoJob::STATUS_PROCESSING,
        AiListingVideoJob::STATUS_STITCHING,
        AiListingVideoJob::STATUS_COMPLETED,
        AiListingVideoJob::STATUS_FAILED,
        AiListingVideoJob::STATUS_CANCELLED,
    ];

    /** Video statuses that are active / non-terminal. */
    private const VIDEO_ACTIVE_STATUSES = [
        AiListingVideoJob::STATUS_QUEUED,
        AiListingVideoJob::STATUS_PROCESSING,
        AiListingVideoJob::STATUS_STITCHING,
    ];

    /**
     * Property 6: across many randomized job distributions spread over several
     * shoots and drawing from every status in both tables, the active-queue
     * endpoint returns the namespaced ids of EXACTLY the non-terminal jobs:
     *
     *  - every non-terminal job (photo: pending/processing; video:
     *    queued/processing/stitching) is present exactly once,
     *  - no terminal job (completed/failed/cancelled) is present, and
     *  - there are no duplicates.
     */
    public function test_property_6_active_queue_contains_exactly_non_terminal_jobs(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // Pre-create a fixed pool of shoots the generator assigns jobs to.
        $shoots = [];
        for ($s = 0; $s < self::SHOOT_POOL; $s++) {
            $shoots[] = Shoot::factory()->create();
        }

        mt_srand(self::SEED);

        $sawActive = false;   // an iteration that produced at least one active job
        $sawTerminal = false; // an iteration that created at least one terminal job
        $sawEmpty = false;    // an iteration with no active jobs at all

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // The second iteration deliberately forces the empty (no-active) edge
            // case by creating only terminal jobs.
            $forceTerminalOnly = ($i === 1);

            $expectedActiveIds = []; // namespaced ids of the non-terminal jobs

            for ($s = 0; $s < self::SHOOT_POOL; $s++) {
                $shoot = $shoots[$s];

                $photoCount = mt_rand(0, 4);
                for ($n = 0; $n < $photoCount; $n++) {
                    $status = $forceTerminalOnly
                        ? $this->randomTerminalPhotoStatus()
                        : self::PHOTO_STATUSES[mt_rand(0, count(self::PHOTO_STATUSES) - 1)];

                    $job = $this->createPhotoJob($shoot, $admin, $status);

                    if (in_array($status, self::PHOTO_ACTIVE_STATUSES, true)) {
                        $expectedActiveIds[] = 'photo-' . $job->id;
                        $sawActive = true;
                    } else {
                        $sawTerminal = true;
                    }
                }

                $videoCount = mt_rand(0, 4);
                for ($n = 0; $n < $videoCount; $n++) {
                    $status = $forceTerminalOnly
                        ? $this->randomTerminalVideoStatus()
                        : self::VIDEO_STATUSES[mt_rand(0, count(self::VIDEO_STATUSES) - 1)];

                    $job = $this->createVideoJob($shoot, $admin, $status);

                    if (in_array($status, self::VIDEO_ACTIVE_STATUSES, true)) {
                        $expectedActiveIds[] = 'video-' . $job->id;
                        $sawActive = true;
                    } else {
                        $sawTerminal = true;
                    }
                }
            }

            if (count($expectedActiveIds) === 0) {
                $sawEmpty = true;
            }

            $counterexample = sprintf(
                'Property 6 violated (seed=%d, iteration=%d): expected %d active job(s), '
                . 'expectedActiveIds=%s.',
                self::SEED,
                $i,
                count($expectedActiveIds),
                json_encode($expectedActiveIds)
            );

            $response = $this->getJson(self::ACTIVE_QUEUE_URL);
            $response->assertOk();

            $data = $response->json('data');
            $this->assertIsArray($data, $counterexample . ' response data is not an array.');

            $returnedIds = array_map(fn ($job) => $job['id'], $data);

            // No duplicates: each job appears exactly once.
            $this->assertSame(
                count($returnedIds),
                count(array_unique($returnedIds)),
                $counterexample . ' a job appeared more than once in the active queue.'
            );

            // The returned set equals EXACTLY the set of non-terminal jobs:
            // no terminal job present, no active job missing.
            $this->assertEqualsCanonicalizing(
                $expectedActiveIds,
                $returnedIds,
                $counterexample . ' active queue does not match the set of non-terminal jobs.'
            );

            // Every returned job reports a non-terminal status for its type.
            foreach ($data as $job) {
                if ($job['job_type'] === 'photo') {
                    $this->assertContains(
                        $job['status'],
                        self::PHOTO_ACTIVE_STATUSES,
                        $counterexample . ' a returned photo job has a terminal status.'
                    );
                } else {
                    $this->assertContains(
                        $job['status'],
                        self::VIDEO_ACTIVE_STATUSES,
                        $counterexample . ' a returned video job has a terminal status.'
                    );
                }
            }

            // Reset so each iteration's aggregation is computed in isolation.
            AiEditingJob::query()->delete();
            AiListingVideoJob::query()->delete();
        }

        // Sanity: the generator exercised active jobs, terminal jobs, and the
        // no-active (empty queue) case that make the iff property meaningful.
        $this->assertTrue($sawActive, 'Generator never created a non-terminal (active) job.');
        $this->assertTrue($sawTerminal, 'Generator never created a terminal job (nothing to exclude).');
        $this->assertTrue($sawEmpty, 'Generator never exercised the empty active-queue case.');
    }

    private function randomTerminalPhotoStatus(): string
    {
        $terminal = [
            AiEditingJob::STATUS_COMPLETED,
            AiEditingJob::STATUS_FAILED,
            AiEditingJob::STATUS_CANCELLED,
        ];

        return $terminal[mt_rand(0, count($terminal) - 1)];
    }

    private function randomTerminalVideoStatus(): string
    {
        $terminal = [
            AiListingVideoJob::STATUS_COMPLETED,
            AiListingVideoJob::STATUS_FAILED,
            AiListingVideoJob::STATUS_CANCELLED,
        ];

        return $terminal[mt_rand(0, count($terminal) - 1)];
    }

    private function createPhotoJob(Shoot $shoot, User $user, string $status): AiEditingJob
    {
        return AiEditingJob::create([
            'shoot_id'           => $shoot->id,
            'user_id'            => $user->id,
            'status'             => $status,
            'editing_type'       => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => 'https://example.test/photo.jpg',
        ]);
    }

    private function createVideoJob(Shoot $shoot, User $user, string $status): AiListingVideoJob
    {
        return AiListingVideoJob::create([
            'shoot_id'          => $shoot->id,
            'user_id'           => $user->id,
            'provider'          => 'fal',
            'selected_file_ids' => [1, 2, 3, 4, 5, 6],
            'target_seconds'    => 30,
            'status'            => $status,
        ]);
    }
}
