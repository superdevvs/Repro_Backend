<?php

namespace Tests\Feature\Properties;

use App\Models\ServiceArea;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\Shoots\TestShoot\TestShootService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 19:
 * "Test_Shoot scoping, eligibility, assignment, and schedule appearance"
 *
 * Validates: Requirements 10.7, 10.8, 10.9, 10.10
 *
 * Exercises the production {@see TestShootService} (resolved from the container, so the
 * real {@see \App\Services\ServiceAreaMatcher} and {@see \App\Services\Shoots\ShootDateService}
 * collaborators are used) plus the canonical photographer schedule read path
 * {@see ScheduleDateScopeService::shootsForLocalDate()}. For an arbitrary service-area scope
 * (kind ∈ {region, state, area}, value) and an arbitrary photographer dataset, the following
 * sub-properties hold universally:
 *
 *   (S) **Scoping (AC 10.7)** — the Test_Shoot created by `create()` is persisted with
 *       `shoot_type = internal_test`, the requested `service_area_kind` / `service_area_value`
 *       scope, the requested IANA timezone, and a `scheduled_date` equal to the local calendar
 *       day in that timezone (so a UTC instant that falls on a different UTC day still records
 *       the intended local day).
 *
 *   (E) **Eligibility (AC 10.8)** — `eligiblePhotographers()` returns EXACTLY the photographers
 *       whose service areas contain an entry matching the Test_Shoot's (kind, value), compared
 *       case-insensitively on value. No matching photographer is missing; no non-matching
 *       photographer is included. The expected set is computed independently of the matcher
 *       (a second, hand-rolled predicate) so the matcher cannot "define its own truth".
 *
 *   (A) **Assignment (AC 10.9)** — after `assign()`, the chosen photographer is linked to the
 *       Test_Shoot (`photographer_id` persisted to disk).
 *
 *   (D) **Schedule appearance (AC 10.10)** — the assigned Test_Shoot is returned by the
 *       photographer-scoped schedule query for its local calendar day, and is NOT returned for
 *       any OTHER photographer's schedule on that day. This is the same query the Schedule_View
 *       reads through, so "appears in that photographer's schedule" is asserted against the real
 *       read path rather than a bespoke lookup.
 *
 * Because no PHP property-based-testing library is installed, this test follows the repo's
 * "strong randomization plus deterministic edge cases" convention (see
 * {@see ServiceAreaAssignmentRoundTripTest}): 20 seeded-random scenarios plus deterministic
 * edge cases (single matching photographer, a timezone day-boundary instant, and a
 * case-insensitive value match). The same universal property must hold for every input.
 */
class TestShootScopingEligibilityAssignmentSchedulePropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 10 randomized iterations; we run 20. */
    private const RANDOM_ITERATIONS = 20;

    /** Deterministic seed so failures reproduce exactly (seeded-randomization style). */
    private const SEED = 20_100_519;

    /**
     * Value pool per kind. Gives the generator enough distinct material to build a scope plus
     * distractor areas across all three granularities.
     */
    private const VALUE_POOL = [
        ServiceArea::KIND_REGION => ['Northeast', 'Southeast', 'Midwest', 'Southwest', 'West'],
        ServiceArea::KIND_STATE => ['MD', 'VA', 'CA', 'NY', 'TX', 'FL', 'WA'],
        ServiceArea::KIND_AREA => ['DC Metro', 'Bay Area', 'NYC Metro', 'LA Metro', 'Greater Boston'],
    ];

    /** A spread of IANA timezones whose UTC offset can flip the calendar day. */
    private const TIMEZONES = [
        'America/New_York',
        'America/Los_Angeles',
        'America/Chicago',
        'Asia/Tokyo',
        'Australia/Sydney',
        'Europe/London',
    ];

    private function service(): TestShootService
    {
        return app(TestShootService::class);
    }

    private function schedule(): ScheduleDateScopeService
    {
        return app(ScheduleDateScopeService::class);
    }

    /**
     * The property: scoping + eligibility + assignment + schedule appearance.
     *
     * Validates: Requirements 10.7, 10.8, 10.9, 10.10
     */
    #[Test]
    public function test_shoot_scopes_filters_eligibility_assigns_and_appears_in_schedule(): void
    {
        mt_srand(self::SEED);

        foreach ($this->casesGenerator() as $i => $case) {
            // Isolate per-date cache buckets between iterations so a previous schedule read
            // cannot mask a regression in this one. Each iteration also uses fresh shoots and
            // scopes its schedule query by photographer_id, so state cannot bleed across cases.
            Cache::flush();

            $context = "iteration {$i} ({$case['label']})";
            $scope = $case['scope'];          // ['kind' => ..., 'value' => ...]
            $timezone = $case['timezone'];
            $when = $case['when'];            // CarbonImmutable absolute instant

            // ---- Setup: build the photographer dataset ----------------------------------
            // Each spec entry is ['areas' => list<['kind','value']>]; we create a real
            // photographer and attach the given service areas via the pivot.
            $photographers = [];
            foreach ($case['photographers'] as $spec) {
                $photographers[] = $this->makePhotographer($spec['areas']);
            }

            // Expected eligible set computed by an INDEPENDENT predicate (case-insensitive
            // value match on the same kind) — deliberately not the matcher under test.
            $expectedEligibleIds = [];
            foreach ($case['photographers'] as $idx => $spec) {
                if ($this->areasMatchScope($spec['areas'], $scope)) {
                    $expectedEligibleIds[] = $photographers[$idx]->id;
                }
            }
            sort($expectedEligibleIds);

            // ---- (S) Scoping: create() persists an internal_test shoot with the scope ----
            $shoot = $this->service()->create($scope, $when, $timezone);
            $shoot->refresh();

            $this->assertSame(
                Shoot::SHOOT_TYPE_INTERNAL_TEST,
                $shoot->shoot_type,
                "[S] Test_Shoot must be classified internal_test for {$context}"
            );
            $this->assertSame($scope['kind'], $shoot->service_area_kind, "[S] kind scope persisted for {$context}");
            $this->assertSame($scope['value'], $shoot->service_area_value, "[S] value scope persisted for {$context}");
            $this->assertSame($timezone, $shoot->timezone, "[S] region timezone persisted for {$context}");

            $expectedLocalDay = $when->setTimezone($timezone)->format('Y-m-d');
            $this->assertSame(
                $expectedLocalDay,
                $shoot->scheduled_date->format('Y-m-d'),
                "[S] scheduled_date must equal the local calendar day in the region timezone for {$context}"
            );

            // ---- (E) Eligibility: matcher returns EXACTLY the matching photographers -----
            $loaded = User::with('serviceAreas')
                ->whereIn('id', collect($photographers)->pluck('id'))
                ->get();

            $eligibleIds = $this->service()
                ->eligiblePhotographers($shoot, $loaded)
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $this->assertSame(
                $expectedEligibleIds,
                $eligibleIds,
                "[E] eligiblePhotographers() must return exactly the scope-matching photographers for {$context}"
            );

            // ---- (A) Assignment: chosen eligible photographer is linked -----------------
            // Generator guarantees >= 1 eligible photographer so assignment + schedule are
            // always exercised.
            $assigneeId = $expectedEligibleIds[0];
            $assignee = collect($photographers)->firstWhere('id', $assigneeId);

            $this->service()->assign($shoot, $assignee);

            $this->assertDatabaseHas('shoots', [
                'id' => $shoot->id,
                'photographer_id' => $assignee->id,
            ]);
            $this->assertSame(
                $assignee->id,
                (int) $shoot->fresh()->photographer_id,
                "[A] assign() must link the photographer to the Test_Shoot for {$context}"
            );

            // ---- (D) Schedule appearance via the real photographer-scoped read path ------
            $assigneeSchedule = $this->schedule()
                ->shootsForLocalDate(
                    $expectedLocalDay,
                    [],
                    fn ($query) => $query->where('photographer_id', $assignee->id)
                )
                ->pluck('id')
                ->all();

            $this->assertContains(
                $shoot->id,
                $assigneeSchedule,
                "[D] assigned Test_Shoot must appear in the photographer's schedule for its local day for {$context}"
            );

            // The Test_Shoot must NOT leak into another photographer's schedule for that day.
            $otherId = $assignee->id + 100_000; // an id that belongs to no photographer here
            $otherSchedule = $this->schedule()
                ->shootsForLocalDate(
                    $expectedLocalDay,
                    [],
                    fn ($query) => $query->where('photographer_id', $otherId)
                )
                ->pluck('id')
                ->all();

            $this->assertNotContains(
                $shoot->id,
                $otherSchedule,
                "[D] Test_Shoot must be scoped to the assigned photographer's schedule for {$context}"
            );
        }
    }

    /**
     * Generator: 20 seeded-random scenarios + deterministic edge cases.
     *
     * @return list<array{
     *     scope: array{kind:string, value:string},
     *     timezone: string,
     *     when: CarbonImmutable,
     *     photographers: list<array{areas: list<array{kind:string, value:string}>}>,
     *     label: string
     * }>
     */
    private function casesGenerator(): array
    {
        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $kind = array_rand(self::VALUE_POOL);
            $values = self::VALUE_POOL[$kind];
            $scope = ['kind' => $kind, 'value' => $values[array_rand($values)]];
            $timezone = self::TIMEZONES[array_rand(self::TIMEZONES)];

            // A random absolute instant within ~120 days, at a random time-of-day so some
            // instants straddle the UTC day boundary for the chosen timezone.
            $when = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC')
                ->addDays(mt_rand(0, 120))
                ->addHours(mt_rand(0, 23))
                ->addMinutes(mt_rand(0, 59));

            // 2-6 photographers; at least one is guaranteed to match the scope so the
            // assignment + schedule sub-properties are always exercised.
            $count = mt_rand(2, 6);
            $photographers = [];
            $photographers[] = ['areas' => $this->areasIncludingScope($scope)];
            for ($p = 1; $p < $count; $p++) {
                $photographers[] = ['areas' => $this->randomAreas($scope)];
            }
            shuffle($photographers);

            $cases[] = [
                'scope' => $scope,
                'timezone' => $timezone,
                'when' => $when,
                'photographers' => $photographers,
                'label' => "random#{$i} {$scope['kind']}:{$scope['value']} tz={$timezone}",
            ];
        }

        // Edge: exactly one photographer, which matches — minimal eligible set.
        $cases[] = [
            'scope' => ['kind' => ServiceArea::KIND_STATE, 'value' => 'MD'],
            'timezone' => 'America/New_York',
            'when' => CarbonImmutable::parse('2026-06-15 14:00:00', 'UTC'),
            'photographers' => [
                ['areas' => [['kind' => ServiceArea::KIND_STATE, 'value' => 'MD']]],
            ],
            'label' => 'edge: single matching photographer',
        ];

        // Edge: UTC instant lands on the NEXT UTC day vs the local day in New_York.
        // 2026-03-16 03:30 UTC == 2026-03-15 23:30 America/New_York → local day is the 15th.
        $cases[] = [
            'scope' => ['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast'],
            'timezone' => 'America/New_York',
            'when' => CarbonImmutable::parse('2026-03-16 03:30:00', 'UTC'),
            'photographers' => [
                ['areas' => [['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast']]],
                ['areas' => [['kind' => ServiceArea::KIND_STATE, 'value' => 'NY']]], // distractor
            ],
            'label' => 'edge: timezone day-boundary instant',
        ];

        // Edge: case-insensitive value match — photographer assigned lower-case "md"
        // must still be eligible for a scope value of "MD" (matcher uses strcasecmp).
        $cases[] = [
            'scope' => ['kind' => ServiceArea::KIND_STATE, 'value' => 'MD'],
            'timezone' => 'America/Los_Angeles',
            'when' => CarbonImmutable::parse('2026-07-01 09:00:00', 'UTC'),
            'photographers' => [
                ['areas' => [['kind' => ServiceArea::KIND_STATE, 'value' => 'md']]],
                ['areas' => [['kind' => ServiceArea::KIND_STATE, 'value' => 'VA']]], // distractor
            ],
            'label' => 'edge: case-insensitive value match',
        ];

        return $cases;
    }

    /**
     * Build an area list that is guaranteed to contain the scope, plus 0-2 distractors.
     *
     * @param  array{kind:string, value:string}  $scope
     * @return list<array{kind:string, value:string}>
     */
    private function areasIncludingScope(array $scope): array
    {
        $areas = [$scope];
        foreach ($this->randomAreas($scope, mt_rand(0, 2)) as $extra) {
            $areas[] = $extra;
        }

        return $this->dedupeAreas($areas);
    }

    /**
     * Build a random list of distractor areas that do NOT match the scope.
     *
     * @param  array{kind:string, value:string}  $scope
     * @return list<array{kind:string, value:string}>
     */
    private function randomAreas(array $scope, ?int $size = null): array
    {
        $size ??= mt_rand(0, 3);
        $pool = [];
        foreach (self::VALUE_POOL as $kind => $values) {
            foreach ($values as $value) {
                // Exclude the exact scope pair so these stay non-matching.
                if ($kind === $scope['kind'] && strcasecmp($value, $scope['value']) === 0) {
                    continue;
                }
                $pool[] = ['kind' => $kind, 'value' => $value];
            }
        }
        shuffle($pool);

        return $this->dedupeAreas(array_slice($pool, 0, $size));
    }

    /**
     * Independent eligibility predicate (NOT the matcher under test): a photographer's areas
     * match the scope iff one shares the kind and a case-insensitively equal value.
     *
     * @param  list<array{kind:string, value:string}>  $areas
     * @param  array{kind:string, value:string}  $scope
     */
    private function areasMatchScope(array $areas, array $scope): bool
    {
        foreach ($areas as $area) {
            if ($area['kind'] === $scope['kind'] && strcasecmp($area['value'], $scope['value']) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a photographer with the given service areas attached via the pivot.
     *
     * @param  list<array{kind:string, value:string}>  $areas
     */
    private function makePhotographer(array $areas): User
    {
        $photographer = User::factory()->photographer()->create();

        foreach ($areas as $area) {
            $serviceArea = ServiceArea::firstOrCreate(
                ['kind' => $area['kind'], 'value' => $area['value']],
            );
            $photographer->serviceAreas()->syncWithoutDetaching([$serviceArea->id]);
        }

        return $photographer;
    }

    /**
     * Remove duplicate (kind, value) pairs (case-insensitive on value).
     *
     * @param  list<array{kind:string, value:string}>  $areas
     * @return list<array{kind:string, value:string}>
     */
    private function dedupeAreas(array $areas): array
    {
        $seen = [];
        $out = [];
        foreach ($areas as $area) {
            $key = $area['kind'] . ':' . strtolower($area['value']);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $area;
            }
        }

        return array_values($out);
    }
}
