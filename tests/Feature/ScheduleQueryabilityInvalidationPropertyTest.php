<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\ShootWorkflowService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 1: Newly scheduled shoot is immediately queryable
 *
 * Validates: Requirements 8.1, 8.3
 *
 * For any shoot scheduled for a given date, querying the schedule for that date
 * within the same request lifecycle returns the shoot, and the affected date's
 * cached schedule data is invalidated so the next read includes it.
 *
 * Concretely, this test asserts three universal sub-properties for arbitrary
 * (UTC-instant, IANA-timezone) tuples:
 *
 *   (A) After ShootWorkflowService::schedule() completes for a fresh shoot,
 *       the per-date cache bucket for the new local calendar day is
 *       invalidated within the same request, AND a subsequent
 *       ScheduleDateScopeService read for that date returns the shoot.
 *
 *   (B) On a reschedule that moves a shoot between two days, BOTH the old and
 *       the new dates' per-date cache buckets are invalidated within the same
 *       request.
 *
 *   (C) After the reschedule, a subsequent read for the OLD date no longer
 *       returns the shoot, and a read for the NEW date does.
 *
 * Because no PHP property-based-testing library is installed, the test
 * follows the spec's "strong randomization plus deterministic edge cases"
 * approach: 25+ randomized {old-instant, new-instant, zone} cases plus
 * deterministic edge cases (DST spring-forward, DST fall-back, day-roll
 * inside a single zone, year boundary, cross-zone day-roll). The same
 * universal property must hold for every generated input.
 */
class ScheduleQueryabilityInvalidationPropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Spec mandates >= 25 randomized cases. We run 30 plus 6 deterministic
     * edge cases.
     */
    private const RANDOM_ITERATIONS = 30;

    private ScheduleDateScopeService $scopeService;
    private ShootWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scopeService = app(ScheduleDateScopeService::class);
        $this->workflow = app(ShootWorkflowService::class);
    }

    /**
     * Generator: 30 random + 6 deterministic edge cases.
     *
     * Each tuple is [string $oldUtcInstant, string $newUtcInstant, string $timezone].
     * Within a single iteration the timezone is held constant — the property
     * under test is "the schedule cache for the affected date(s) is busted",
     * so we keep timezone correctness (Property 2) out of this property's
     * scope to keep it a clean assertion of cache invalidation.
     *
     * Edge cases force interesting day-roll behaviour:
     *   - Day-roll inside a single zone (UTC late-night vs early-morning LA).
     *   - DST spring-forward in America/New_York (2026-03-08).
     *   - DST fall-back in America/Los_Angeles (2026-11-01).
     *   - Year boundary crossing in Australia/Sydney.
     *   - Simple +1 day in Europe/London (no DST gap).
     *   - Cross-month +1 month in UTC (catches month-arithmetic errors).
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function casesGenerator(): array
    {
        $zones = [
            'UTC',
            'America/New_York',
            'America/Los_Angeles',
            'Europe/London',
            'Australia/Sydney',
        ];

        $cases = [];

        // Randomized cases. We pick a UTC instant in 2026 and a second instant
        // strictly more than 24 hours later to force a different local
        // calendar day in any IANA zone.
        $base = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');
        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $tz = $zones[array_rand($zones)];

            // Old instant somewhere in the year.
            $oldOffsetMinutes = mt_rand(0, 350 * 24 * 60);
            // New instant: at least 25 hours later (always > 1 calendar day),
            // bounded so we stay within the year for predictability.
            $newDeltaMinutes = mt_rand(25 * 60, 14 * 24 * 60);

            $cases[] = [
                $base->addMinutes($oldOffsetMinutes)->toDateTimeString(),
                $base->addMinutes($oldOffsetMinutes + $newDeltaMinutes)->toDateTimeString(),
                $tz,
            ];
        }

        // Deterministic edge cases.

        // Day-roll inside a single zone: 03:30 UTC on 2026-06-09 is
        // 2026-06-08 20:30 PDT, while 22:30 UTC on 2026-06-09 is
        // 2026-06-09 15:30 PDT — different local LA days even though
        // both are on the same UTC day.
        $cases[] = ['2026-06-09 03:30:00', '2026-06-09 22:30:00', 'America/Los_Angeles'];

        // DST spring-forward in NY (2026-03-08 02:00 EST -> 03:00 EDT).
        // Both instants fall on adjacent local days that straddle the gap.
        $cases[] = ['2026-03-08 06:30:00', '2026-03-09 06:30:00', 'America/New_York'];

        // DST fall-back in LA (2026-11-01 02:00 PDT -> 01:00 PST).
        $cases[] = ['2026-11-01 09:30:00', '2026-11-02 09:30:00', 'America/Los_Angeles'];

        // Year boundary in Sydney (UTC+11 in summer).
        $cases[] = ['2026-12-31 14:30:00', '2027-01-01 14:30:00', 'Australia/Sydney'];

        // Simple +1 day in London (no DST gap mid-summer).
        $cases[] = ['2026-04-15 14:00:00', '2026-04-16 14:00:00', 'Europe/London'];

        // Cross-month +1 month in UTC (sanity baseline; both local days exist).
        $cases[] = ['2026-07-04 12:00:00', '2026-08-04 12:00:00', 'UTC'];

        return $cases;
    }

    /**
     * The property: for any random {scheduled_at, timezone}, scheduling and
     * rescheduling busts the right per-date cache bucket(s) and the next
     * read returns the updated row(s).
     *
     * Validates: Requirements 8.1, 8.3
     */
    public function test_newly_scheduled_shoot_is_immediately_queryable_and_cache_buckets_are_invalidated(): void
    {
        foreach ($this->casesGenerator() as $i => [$oldInstant, $newInstant, $tz]) {
            // Reset cache state between iterations so loader-call counts are
            // never polluted by a previous iteration's cached entries. The
            // database keeps accumulating users/shoots within the test run,
            // which is fine because we filter assertions by the specific
            // shoot id created in this iteration.
            Cache::flush();

            $oldDt = Carbon::parse($oldInstant, 'UTC');
            $newDt = Carbon::parse($newInstant, 'UTC');

            $oldLocalDate = $this->scopeService->localDateFromValues($oldDt, null, null, $tz);
            $newLocalDate = $this->scopeService->localDateFromValues($newDt, null, null, $tz);

            // Skip cases where the random generator happened to produce the
            // same local day on both sides — the multi-day property under
            // test is not meaningful there.
            if ($oldLocalDate === $newLocalDate) {
                continue;
            }

            $context = sprintf(
                'iteration %d (oldInstant=%s, newInstant=%s, tz=%s, oldLocalDate=%s, newLocalDate=%s)',
                $i,
                $oldInstant,
                $newInstant,
                $tz,
                $oldLocalDate,
                $newLocalDate
            );

            // ----------------------------------------------------------------
            // Setup: fresh shoot in 'on_hold' status with no scheduled date
            // yet. on_hold is one of two states (along with 'scheduled')
            // that ShootWorkflowService::schedule() accepts unconditionally.
            // ----------------------------------------------------------------
            $shoot = Shoot::factory()->create([
                'status' => Shoot::STATUS_ON_HOLD,
                'workflow_status' => Shoot::STATUS_ON_HOLD,
                'scheduled_at' => null,
                'scheduled_date' => null,
                'time' => null,
                'timezone' => $tz,
            ]);

            // ----------------------------------------------------------------
            // (A) Schedule the shoot for $oldLocalDate. Prime the read-through
            //     cache for $oldLocalDate first so we can detect that the
            //     bucket is invalidated by counting loader re-invocations.
            // ----------------------------------------------------------------
            $primeSuffix = 'newly_scheduled_invalidation_test_' . $i;
            $primeCalls = 0;
            $primeLoader = function () use (&$primeCalls, $oldLocalDate) {
                $primeCalls++;
                return $this->scopeService->shootsForLocalDate($oldLocalDate)->pluck('id')->all();
            };

            $beforeIds = $this->scopeService->remember($oldLocalDate, $primeLoader, $primeSuffix);

            $this->assertSame(
                1,
                $primeCalls,
                "[A] Priming the cache for {$oldLocalDate} must invoke the loader exactly once for {$context}"
            );
            $this->assertNotContains(
                $shoot->id,
                $beforeIds,
                "[A] Before scheduling, the {$oldLocalDate} bucket must NOT contain the on-hold shoot for {$context}"
            );

            // Schedule via the workflow service (the property under test).
            // Re-fetch the shoot so the service sees the freshly-saved state
            // (no scheduled_at yet) and computes the correct previous date.
            $shoot->refresh();
            $this->workflow->schedule($shoot, $oldDt);

            // Re-read with the SAME suffix. The bucket for $oldLocalDate must
            // have been invalidated; the loader runs again and now sees the
            // shoot scheduled for that date.
            $afterIds = $this->scopeService->remember($oldLocalDate, $primeLoader, $primeSuffix);

            $this->assertSame(
                2,
                $primeCalls,
                "[A] After ShootWorkflowService::schedule(), the per-date cache bucket for {$oldLocalDate} must be invalidated (loader re-invoked) for {$context}"
            );
            $this->assertContains(
                $shoot->id,
                $afterIds,
                "[A] After ShootWorkflowService::schedule(), reading the per-date schedule bucket for {$oldLocalDate} must include the shoot for {$context}"
            );

            // ----------------------------------------------------------------
            // (B + C) Reschedule from $oldLocalDate to $newLocalDate. Both
            //         buckets must be invalidated within the same request,
            //         and subsequent reads must reflect the move.
            // ----------------------------------------------------------------
            $oldSuffix = 'reschedule_invalidation_test_old_' . $i;
            $newSuffix = 'reschedule_invalidation_test_new_' . $i;

            $oldCalls = 0;
            $newCalls = 0;
            $oldLoader = function () use (&$oldCalls, $oldLocalDate) {
                $oldCalls++;
                return $this->scopeService->shootsForLocalDate($oldLocalDate)->pluck('id')->all();
            };
            $newLoader = function () use (&$newCalls, $newLocalDate) {
                $newCalls++;
                return $this->scopeService->shootsForLocalDate($newLocalDate)->pluck('id')->all();
            };

            $oldBefore = $this->scopeService->remember($oldLocalDate, $oldLoader, $oldSuffix);
            $newBefore = $this->scopeService->remember($newLocalDate, $newLoader, $newSuffix);

            $this->assertSame(1, $oldCalls, "[B] Priming the OLD ({$oldLocalDate}) bucket must invoke its loader once for {$context}");
            $this->assertSame(1, $newCalls, "[B] Priming the NEW ({$newLocalDate}) bucket must invoke its loader once for {$context}");
            $this->assertContains(
                $shoot->id,
                $oldBefore,
                "[B] Before reschedule, the OLD ({$oldLocalDate}) bucket must contain the shoot for {$context}"
            );
            $this->assertNotContains(
                $shoot->id,
                $newBefore,
                "[B] Before reschedule, the NEW ({$newLocalDate}) bucket must NOT contain the shoot for {$context}"
            );

            // Reschedule.
            $shoot->refresh();
            $this->workflow->schedule($shoot, $newDt);

            $oldAfter = $this->scopeService->remember($oldLocalDate, $oldLoader, $oldSuffix);
            $newAfter = $this->scopeService->remember($newLocalDate, $newLoader, $newSuffix);

            $this->assertSame(
                2,
                $oldCalls,
                "[B] After reschedule, the OLD ({$oldLocalDate}) bucket must be invalidated (loader re-invoked) for {$context}"
            );
            $this->assertSame(
                2,
                $newCalls,
                "[B] After reschedule, the NEW ({$newLocalDate}) bucket must be invalidated (loader re-invoked) for {$context}"
            );

            $this->assertNotContains(
                $shoot->id,
                $oldAfter,
                "[C] After reschedule, the OLD ({$oldLocalDate}) bucket must NOT contain the shoot for {$context}"
            );
            $this->assertContains(
                $shoot->id,
                $newAfter,
                "[C] After reschedule, the NEW ({$newLocalDate}) bucket MUST contain the shoot for {$context}"
            );

            // Detach the shoot from subsequent iterations: deleting routes
            // through the same model events that invalidate buckets, leaving
            // a clean slate for the next case alongside Cache::flush().
            $shoot->delete();
        }
    }
}
