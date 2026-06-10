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
 * Property-based tests for the Studio Metrics hero success-rate computation.
 *
 * Feature: ai-editing-default-page
 * Property 2: Success rate computation.
 *   For any set of photo (AiEditingJob) and listing-video (AiListingVideoJob)
 *   jobs, when the number of completed + failed (terminal, non-cancelled) jobs
 *   is greater than zero, the reported success_rate equals
 *   completed / (completed + failed) * 100; and when that sum is zero, the
 *   reported success_rate is exactly zero. `cancelled` and active
 *   (non-terminal) jobs are excluded from the denominator.
 *
 * Validates: Requirements 3.4, 3.5
 *
 * The backend has no dedicated property-testing library, so these tests use a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over randomized combinations of job statuses across both job tables. The fixed
 * seed makes any counterexample reproducible.
 *
 * @group ai-editing-default-page
 */
class StudioHeroSuccessRateTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 120;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251212;

    /** Endpoint under test. */
    private const HERO_URL = '/api/studio/metrics/hero';

    /** Photo (AiEditingJob) active / non-terminal statuses. */
    private const PHOTO_ACTIVE = [
        AiEditingJob::STATUS_PENDING,
        AiEditingJob::STATUS_PROCESSING,
    ];

    /** Video (AiListingVideoJob) active / non-terminal statuses. */
    private const VIDEO_ACTIVE = [
        AiListingVideoJob::STATUS_QUEUED,
        AiListingVideoJob::STATUS_PROCESSING,
        AiListingVideoJob::STATUS_STITCHING,
    ];

    /**
     * Property 2: across many randomized status mixes the reported success_rate
     * matches completed / (completed + failed) * 100 (rounded to one decimal),
     * with cancelled and active jobs excluded from the denominator, and equals
     * exactly 0 when there are no terminal (completed + failed) jobs.
     */
    public function test_property_2_success_rate_matches_completed_over_terminal(): void
    {
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        mt_srand(self::SEED);

        $sawZeroTerminal = false;
        $sawNonZeroTerminal = false;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Randomized counts for each status bucket across both tables.
            // The first two iterations deliberately force the "no terminal jobs"
            // edge case (Req 3.5): only cancelled/active jobs, or no jobs at all.
            if ($i === 0) {
                $counts = [
                    'photo_completed' => 0, 'photo_failed' => 0,
                    'photo_cancelled' => mt_rand(0, 4), 'photo_active' => mt_rand(0, 4),
                    'video_completed' => 0, 'video_failed' => 0,
                    'video_cancelled' => mt_rand(0, 4), 'video_active' => mt_rand(0, 4),
                ];
            } elseif ($i === 1) {
                // Completely empty: no jobs at all -> success_rate must be 0.
                $counts = array_fill_keys([
                    'photo_completed', 'photo_failed', 'photo_cancelled', 'photo_active',
                    'video_completed', 'video_failed', 'video_cancelled', 'video_active',
                ], 0);
            } else {
                $counts = [
                    'photo_completed' => mt_rand(0, 5),
                    'photo_failed'    => mt_rand(0, 5),
                    'photo_cancelled' => mt_rand(0, 3),
                    'photo_active'    => mt_rand(0, 3),
                    'video_completed' => mt_rand(0, 5),
                    'video_failed'    => mt_rand(0, 5),
                    'video_cancelled' => mt_rand(0, 3),
                    'video_active'    => mt_rand(0, 3),
                ];
            }

            $this->seedJobs($shoot, $admin, $counts);

            $completed = $counts['photo_completed'] + $counts['video_completed'];
            $failed    = $counts['photo_failed'] + $counts['video_failed'];
            $terminal  = $completed + $failed;

            $expected = $terminal > 0 ? round($completed / $terminal * 100, 1) : 0.0;

            if ($terminal === 0) {
                $sawZeroTerminal = true;
            } else {
                $sawNonZeroTerminal = true;
            }

            $counterexample = sprintf(
                'Property 2 violated (seed=%d, iteration=%d): counts=%s => completed=%d failed=%d terminal=%d, '
                . 'expected success_rate=%s.',
                self::SEED,
                $i,
                json_encode($counts),
                $completed,
                $failed,
                $terminal,
                $expected
            );

            $response = $this->getJson(self::HERO_URL);
            $response->assertOk();

            $actual = $response->json('data.success_rate');

            $this->assertNotNull($actual, $counterexample . ' Missing success_rate in response.');
            $this->assertEqualsWithDelta($expected, (float) $actual, 0.0001, $counterexample);

            // ai_jobs_completed should equal the total completed jobs across both tables.
            $this->assertSame(
                $completed,
                (int) $response->json('data.ai_jobs_completed'),
                $counterexample . ' ai_jobs_completed mismatch.'
            );

            // Reset so each iteration's aggregation is computed in isolation.
            AiEditingJob::query()->delete();
            AiListingVideoJob::query()->delete();
        }

        // Sanity: the generator exercised both the zero-terminal and
        // non-zero-terminal branches of the success-rate computation.
        $this->assertTrue($sawZeroTerminal, 'Generator never exercised the no-terminal-jobs (success_rate=0) case.');
        $this->assertTrue($sawNonZeroTerminal, 'Generator never exercised the non-zero-terminal success_rate case.');
    }

    /**
     * Seed the requested number of photo and video jobs in each status bucket.
     *
     * @param  array<string,int>  $counts
     */
    private function seedJobs(Shoot $shoot, User $user, array $counts): void
    {
        for ($n = 0; $n < $counts['photo_completed']; $n++) {
            $this->createPhotoJob($shoot, $user, AiEditingJob::STATUS_COMPLETED);
        }
        for ($n = 0; $n < $counts['photo_failed']; $n++) {
            $this->createPhotoJob($shoot, $user, AiEditingJob::STATUS_FAILED);
        }
        for ($n = 0; $n < $counts['photo_cancelled']; $n++) {
            $this->createPhotoJob($shoot, $user, AiEditingJob::STATUS_CANCELLED);
        }
        for ($n = 0; $n < $counts['photo_active']; $n++) {
            $this->createPhotoJob($shoot, $user, self::PHOTO_ACTIVE[mt_rand(0, count(self::PHOTO_ACTIVE) - 1)]);
        }

        for ($n = 0; $n < $counts['video_completed']; $n++) {
            $this->createVideoJob($shoot, $user, AiListingVideoJob::STATUS_COMPLETED);
        }
        for ($n = 0; $n < $counts['video_failed']; $n++) {
            $this->createVideoJob($shoot, $user, AiListingVideoJob::STATUS_FAILED);
        }
        for ($n = 0; $n < $counts['video_cancelled']; $n++) {
            $this->createVideoJob($shoot, $user, AiListingVideoJob::STATUS_CANCELLED);
        }
        for ($n = 0; $n < $counts['video_active']; $n++) {
            $this->createVideoJob($shoot, $user, self::VIDEO_ACTIVE[mt_rand(0, count(self::VIDEO_ACTIVE) - 1)]);
        }
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
