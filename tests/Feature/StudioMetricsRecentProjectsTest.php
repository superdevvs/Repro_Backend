<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Property-based tests for the Studio Metrics recent-projects aggregation.
 *
 * Feature: ai-editing-default-page
 * Property 5: Recent projects are deduplicated and ordered by most recent activity.
 *   For any set of photo and listing-video jobs, the recent-projects result
 *   contains each shoot at most once, is ordered by most recent activity time
 *   descending, and each project's latest_status and latest_job_type are taken
 *   from that shoot's single most-recent job.
 *
 * Validates: Requirements 5.2
 *
 * The backend has no dedicated property-testing library, so these tests use a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over randomized combinations of photo and video jobs spread across several
 * shoots. Each job is given a globally-unique activity timestamp within an
 * iteration so the "single most-recent job per shoot" and the descending
 * ordering are unambiguous (no tie-break required). Some shoots are deliberately
 * given both photo and video jobs so the dedupe path (a shoot contributing a
 * single entry) and the cross-type "latest wins" path are exercised. The fixed
 * seed makes any counterexample reproducible.
 *
 * @group ai-editing-default-page
 */
class StudioMetricsRecentProjectsTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 120;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251214;

    /** Endpoint under test. A large limit is requested so every shoot is returned. */
    private const RECENT_URL = '/api/studio/metrics/recent-projects?limit=100';

    /** Number of distinct shoots available to the generator. */
    private const SHOOT_POOL = 6;

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
     * Property 5: across many randomized job distributions spread over several
     * shoots (including shoots owning both photo and video jobs):
     *
     *  - the result contains each shoot at most once (dedupe),
     *  - the result is ordered by last_activity_at descending, and
     *  - each project's latest_status / latest_job_type / last_activity_at come
     *    from that shoot's single most-recent job.
     */
    public function test_property_5_recent_projects_deduped_and_ordered_by_recent_activity(): void
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
        $sawCrossTypeLatest = false; // a shoot whose latest job is video while it also has photo (or vice versa)
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
                    $photoCounts[$s] = mt_rand(0, 3);
                    $videoCounts[$s] = mt_rand(0, 3);
                }
            }

            // Build the flat list of jobs to create across all shoots.
            $jobSpecs = [];
            for ($s = 0; $s < self::SHOOT_POOL; $s++) {
                $shoot = $shoots[$s];

                for ($n = 0; $n < $photoCounts[$s]; $n++) {
                    $jobSpecs[] = [
                        'shoot'    => $shoot,
                        'job_type' => 'photo',
                        'status'   => self::PHOTO_STATUSES[mt_rand(0, count(self::PHOTO_STATUSES) - 1)],
                    ];
                }
                for ($n = 0; $n < $videoCounts[$s]; $n++) {
                    $jobSpecs[] = [
                        'shoot'    => $shoot,
                        'job_type' => 'video',
                        'status'   => self::VIDEO_STATUSES[mt_rand(0, count(self::VIDEO_STATUSES) - 1)],
                    ];
                }

                if ($photoCounts[$s] > 0 && $videoCounts[$s] > 0) {
                    $sawSharedShoot = true;
                }
            }

            // Assign each job a globally-unique activity timestamp within this
            // iteration. Shuffling decouples creation order from recency so the
            // endpoint cannot accidentally pass by relying on insertion order.
            $this->shuffleInPlace($jobSpecs);

            $base = Carbon::create(2025, 1, 1, 12, 0, 0);
            $jobCount = count($jobSpecs);
            foreach ($jobSpecs as $idx => &$spec) {
                // Distinct timestamps: earlier index => more recent (larger time).
                $spec['activity'] = $base->copy()->subMinutes($idx);
                $spec['model'] = $this->createJob($spec, $admin);
            }
            unset($spec);

            // Compute the expected per-shoot latest job (max activity timestamp).
            // expected[shoot_id] = ['status'=>, 'job_type'=>, 'ts'=>Carbon]
            $expected = [];
            foreach ($jobSpecs as $spec) {
                $shootId = $spec['shoot']->id;
                $ts = $spec['activity'];
                if (! isset($expected[$shootId]) || $ts->greaterThan($expected[$shootId]['ts'])) {
                    $expected[$shootId] = [
                        'status'   => $spec['status'],
                        'job_type' => $spec['job_type'],
                        'ts'       => $ts,
                    ];
                }
            }

            // Track whether a shoot's latest job type differs from another job
            // type it also owns (cross-type "latest wins").
            foreach ($jobSpecs as $spec) {
                $shootId = $spec['shoot']->id;
                if (isset($expected[$shootId]) && $spec['job_type'] !== $expected[$shootId]['job_type']) {
                    $sawCrossTypeLatest = true;
                    break;
                }
            }

            if ($jobCount === 0) {
                $sawEmpty = true;
            }

            // Expected ordering of shoots: by latest activity timestamp desc.
            $expectedOrder = collect($expected)
                ->map(fn ($e, $shootId) => ['shoot_id' => (int) $shootId, 'ts' => $e['ts']])
                ->sortByDesc(fn ($e) => $e['ts']->getTimestamp())
                ->pluck('shoot_id')
                ->values()
                ->all();

            $counterexample = sprintf(
                'Property 5 violated (seed=%d, iteration=%d): photoCounts=%s videoCounts=%s '
                . '=> expected %d distinct project(s), expectedOrder=%s.',
                self::SEED,
                $i,
                json_encode($photoCounts),
                json_encode($videoCounts),
                count($expected),
                json_encode($expectedOrder)
            );

            $response = $this->getJson(self::RECENT_URL);
            $response->assertOk();

            $data = $response->json('data');
            $this->assertIsArray($data, $counterexample . ' response data is not an array.');

            $returnedShootIds = array_map(fn ($p) => (int) $p['shoot_id'], $data);

            // (1) Dedupe: each shoot appears at most once, and the set of shoots
            // matches exactly the shoots that have any job.
            $this->assertSame(
                count($returnedShootIds),
                count(array_unique($returnedShootIds)),
                $counterexample . ' a shoot appeared more than once (dedupe failure).'
            );
            $this->assertEqualsCanonicalizing(
                array_keys($expected),
                $returnedShootIds,
                $counterexample . ' returned shoot set does not match shoots with jobs.'
            );

            // (2) Ordering: results are sorted by last_activity_at descending.
            // With globally-unique timestamps the order is strict and equals the
            // expected order computed above.
            $this->assertSame(
                $expectedOrder,
                $returnedShootIds,
                $counterexample . ' results are not ordered by most recent activity descending.'
            );

            $previousTs = null;
            foreach ($data as $project) {
                $ts = Carbon::parse($project['last_activity_at'])->getTimestamp();
                if ($previousTs !== null) {
                    $this->assertLessThanOrEqual(
                        $previousTs,
                        $ts,
                        $counterexample . ' last_activity_at is not monotonically non-increasing.'
                    );
                }
                $previousTs = $ts;
            }

            // (3) Latest fields: each entry's latest_status / latest_job_type /
            // last_activity_at come from that shoot's single most-recent job.
            foreach ($data as $project) {
                $shootId = (int) $project['shoot_id'];
                $this->assertArrayHasKey($shootId, $expected, $counterexample . " unexpected shoot {$shootId} returned.");

                $this->assertSame(
                    $expected[$shootId]['status'],
                    $project['latest_status'],
                    $counterexample . " latest_status mismatch for shoot {$shootId}."
                );
                $this->assertSame(
                    $expected[$shootId]['job_type'],
                    $project['latest_job_type'],
                    $counterexample . " latest_job_type mismatch for shoot {$shootId}."
                );
                $this->assertSame(
                    $expected[$shootId]['ts']->toIso8601String(),
                    $project['last_activity_at'],
                    $counterexample . " last_activity_at mismatch for shoot {$shootId}."
                );
            }

            // Reset so each iteration's aggregation is computed in isolation.
            AiEditingJob::query()->delete();
            AiListingVideoJob::query()->delete();
        }

        // Sanity: the generator exercised the shared-shoot (both job types on one
        // shoot), the cross-type latest-wins, and empty cases that make the
        // dedupe + ordering property meaningful.
        $this->assertTrue($sawSharedShoot, 'Generator never created a shoot owning both photo and video jobs.');
        $this->assertTrue($sawCrossTypeLatest, 'Generator never exercised a shoot whose latest job type differs from another owned type.');
        $this->assertTrue($sawEmpty, 'Generator never exercised the no-jobs (empty result) case.');
    }

    /**
     * Create a photo or video job for the given spec and force its activity
     * timestamp (updated_at) to the spec's assigned, globally-unique value.
     *
     * @param  array{shoot: Shoot, job_type: string, status: string, activity: Carbon}  $spec
     */
    private function createJob(array $spec, User $user)
    {
        if ($spec['job_type'] === 'photo') {
            $job = AiEditingJob::create([
                'shoot_id'           => $spec['shoot']->id,
                'user_id'            => $user->id,
                'status'             => $spec['status'],
                'editing_type'       => AiEditingJob::TYPE_ENHANCE,
                'original_image_url' => 'https://example.test/photo.jpg',
            ]);
            // Bypass Eloquent's auto-touch by setting updated_at via the query
            // builder (the provided value takes precedence over the auto column).
            AiEditingJob::query()->where('id', $job->id)->update(['updated_at' => $spec['activity']]);

            return $job;
        }

        $job = AiListingVideoJob::create([
            'shoot_id'          => $spec['shoot']->id,
            'user_id'           => $user->id,
            'provider'          => 'fal',
            'selected_file_ids' => [1, 2, 3, 4, 5, 6],
            'target_seconds'    => 30,
            'status'            => $spec['status'],
        ]);
        AiListingVideoJob::query()->where('id', $job->id)->update(['updated_at' => $spec['activity']]);

        return $job;
    }

    /**
     * In-place Fisher-Yates shuffle driven by mt_rand so the shuffle is part of
     * the seeded, reproducible generator (PHP's shuffle() is not seeded by mt_srand).
     */
    private function shuffleInPlace(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $items[$i];
            $items[$i] = $items[$j];
            $items[$j] = $tmp;
        }
    }
}
