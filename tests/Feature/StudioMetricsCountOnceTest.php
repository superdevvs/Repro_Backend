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
 * Property-based tests for the Studio Metrics count-once aggregation.
 *
 * Feature: ai-editing-default-page
 * Property 3: Each job is counted exactly once across aggregations.
 *   For any set of AiEditingJob and AiListingVideoJob records (including shoots
 *   that own both photo and video jobs), every aggregated result counts each
 *   individual job at most once, and projects_count counts each distinct shoot
 *   exactly once regardless of how many jobs or job types it has.
 *
 * Validates: Requirements 3.3, 9.1, 9.2, 9.3, 9.4
 *
 * The backend has no dedicated property-testing library, so these tests use a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over randomized combinations of jobs spread across several shoots. Some shoots
 * are deliberately given both photo and video jobs so the dedupe path (a single
 * shoot contributing once to projects_count) is exercised. The fixed seed makes
 * any counterexample reproducible.
 *
 * @group ai-editing-default-page
 */
class StudioMetricsCountOnceTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 120;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251213;

    /** Endpoint under test. */
    private const HERO_URL = '/api/studio/metrics/hero';

    /** Number of distinct shoots available to the generator. */
    private const SHOOT_POOL = 5;

    /** Photo (AiEditingJob) statuses the generator draws from. */
    private const PHOTO_STATUSES = [
        AiEditingJob::STATUS_PENDING,
        AiEditingJob::STATUS_PROCESSING,
        AiEditingJob::STATUS_COMPLETED,
        AiEditingJob::STATUS_FAILED,
        AiEditingJob::STATUS_CANCELLED,
    ];

    /** Video (AiListingVideoJob) statuses the generator draws from. */
    private const VIDEO_STATUSES = [
        AiListingVideoJob::STATUS_QUEUED,
        AiListingVideoJob::STATUS_PROCESSING,
        AiListingVideoJob::STATUS_STITCHING,
        AiListingVideoJob::STATUS_COMPLETED,
        AiListingVideoJob::STATUS_FAILED,
        AiListingVideoJob::STATUS_CANCELLED,
    ];

    /**
     * Property 3: across many randomized job distributions spread over several
     * shoots (including shoots owning both photo and video jobs):
     *
     *  - projects_count equals the number of DISTINCT shoot_ids that have at
     *    least one job in either table (each shoot counted exactly once,
     *    regardless of how many jobs/job types it owns), and
     *  - ai_jobs_completed equals the total number of completed jobs across both
     *    tables (each completed job counted exactly once, no double-counting).
     */
    public function test_property_3_each_job_counted_exactly_once_across_aggregations(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // Pre-create a fixed pool of shoots the generator assigns jobs to.
        $shoots = [];
        for ($s = 0; $s < self::SHOOT_POOL; $s++) {
            $shoots[] = Shoot::factory()->create();
        }

        mt_srand(self::SEED);

        $sawSharedShoot = false; // a shoot owning BOTH photo and video jobs
        $sawEmpty = false;       // an iteration with no jobs at all

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // The second iteration deliberately forces the empty edge case.
            if ($i === 1) {
                $photoCounts = array_fill(0, self::SHOOT_POOL, 0);
                $videoCounts = array_fill(0, self::SHOOT_POOL, 0);
            } else {
                $photoCounts = [];
                $videoCounts = [];
                for ($s = 0; $s < self::SHOOT_POOL; $s++) {
                    $photoCounts[$s] = mt_rand(0, 4);
                    $videoCounts[$s] = mt_rand(0, 4);
                }
            }

            $expectedCompleted = 0;
            $shootsWithAnyJob = [];

            for ($s = 0; $s < self::SHOOT_POOL; $s++) {
                $shoot = $shoots[$s];

                for ($n = 0; $n < $photoCounts[$s]; $n++) {
                    $status = self::PHOTO_STATUSES[mt_rand(0, count(self::PHOTO_STATUSES) - 1)];
                    $this->createPhotoJob($shoot, $admin, $status);
                    if ($status === AiEditingJob::STATUS_COMPLETED) {
                        $expectedCompleted++;
                    }
                }

                for ($n = 0; $n < $videoCounts[$s]; $n++) {
                    $status = self::VIDEO_STATUSES[mt_rand(0, count(self::VIDEO_STATUSES) - 1)];
                    $this->createVideoJob($shoot, $admin, $status);
                    if ($status === AiListingVideoJob::STATUS_COMPLETED) {
                        $expectedCompleted++;
                    }
                }

                if ($photoCounts[$s] > 0 || $videoCounts[$s] > 0) {
                    $shootsWithAnyJob[$shoot->id] = true;
                }
                if ($photoCounts[$s] > 0 && $videoCounts[$s] > 0) {
                    $sawSharedShoot = true;
                }
            }

            $expectedProjects = count($shootsWithAnyJob);
            if ($expectedProjects === 0) {
                $sawEmpty = true;
            }

            $counterexample = sprintf(
                'Property 3 violated (seed=%d, iteration=%d): photoCounts=%s videoCounts=%s => '
                . 'expected projects_count=%d, expected ai_jobs_completed=%d.',
                self::SEED,
                $i,
                json_encode($photoCounts),
                json_encode($videoCounts),
                $expectedProjects,
                $expectedCompleted
            );

            $response = $this->getJson(self::HERO_URL);
            $response->assertOk();

            // projects_count counts each distinct shoot exactly once, regardless
            // of how many jobs or job types that shoot owns.
            $this->assertSame(
                $expectedProjects,
                (int) $response->json('data.projects_count'),
                $counterexample . ' projects_count mismatch (shoot dedupe / count-once).'
            );

            // ai_jobs_completed counts each completed job exactly once across
            // both tables (no double-counting).
            $this->assertSame(
                $expectedCompleted,
                (int) $response->json('data.ai_jobs_completed'),
                $counterexample . ' ai_jobs_completed mismatch (count-once across tables).'
            );

            // Reset so each iteration's aggregation is computed in isolation.
            AiEditingJob::query()->delete();
            AiListingVideoJob::query()->delete();
        }

        // Sanity: the generator exercised the shared-shoot (both job types on one
        // shoot) and empty cases that make the count-once property meaningful.
        $this->assertTrue($sawSharedShoot, 'Generator never created a shoot owning both photo and video jobs.');
        $this->assertTrue($sawEmpty, 'Generator never exercised the no-jobs (projects_count=0) case.');
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
