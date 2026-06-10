<?php

namespace Tests\Feature\Properties;

use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 3: Service-area assignment round-trips
 *
 * Validates: Requirements 10.1, 10.4
 *
 * For an arbitrary photographer and an arbitrary list S of distinct
 * (kind ∈ {region, state, area}, value) service-area pairs, the following
 * sub-properties hold universally for `POST /api/admin/photographers/{user}/service-areas`
 * (which persists ServiceArea rows via syncWithoutDetaching on the
 * `photographer_service_areas` pivot):
 *
 *   (R) **Round-trip equality (AC 10.1, 10.4)** — after a successful 200 response,
 *       the photographer's `serviceAreas` relation, read from a fresh DB lookup
 *       and projected to the set of `(kind, value)` keys, equals S as a set.
 *       No extra rows, no missing rows.
 *
 *   (O) **Order independence (AC 10.4)** — for the same set S, the persisted set
 *       is identical regardless of the insertion order of S in the request. Two
 *       independent random shuffles of the same S yield the same set on disk.
 *       The (R) and (I) sub-properties are evaluated against shuffled inputs to
 *       exercise this directly.
 *
 *   (I) **Idempotence (AC 10.4)** — re-issuing the same assignment for S
 *       (in a second, independent shuffle) does not change the photographer's
 *       set: the persisted set is still exactly S, and the
 *       `photographer_service_areas` pivot still contains exactly |S| rows for
 *       that user (no duplicate pivot rows).
 *
 * Because no PHP property-based-testing library is installed, this test follows
 * the spec's "strong randomization plus deterministic edge cases" approach:
 * 20 randomized scenarios (random size 1-8, random distinct (kind, value) pairs
 * drawn from a fixture pool, two independent shuffles per scenario) plus four
 * deterministic edge cases (minimum size 1, single-kind-only, one of each kind,
 * and a large mixed set). The same universal property must hold for every input.
 */
class ServiceAreaAssignmentRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 10 randomized iterations; we run 20. */
    private const RANDOM_ITERATIONS = 20;

    /**
     * Fixture value pool per kind. Mirrors realistic admin inputs and gives the
     * generator enough material to draw distinct (kind, value) pairs up to the
     * largest scenario size.
     */
    private const VALUE_POOL = [
        ServiceArea::KIND_REGION => ['Northeast', 'Southeast', 'Midwest', 'Southwest', 'West', 'Pacific', 'Mountain'],
        ServiceArea::KIND_STATE => ['MD', 'VA', 'CA', 'NY', 'TX', 'FL', 'WA', 'OR', 'CO', 'IL'],
        ServiceArea::KIND_AREA => ['DC Metro', 'Bay Area', 'NYC Metro', 'LA Metro', 'Greater Boston', 'Houston Metro'],
    ];

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function photographer(): User
    {
        return User::factory()->create(['role' => 'photographer']);
    }

    /**
     * Generator: 20 randomized scenarios + 4 deterministic edge cases.
     *
     * Each scenario is a list of distinct (kind, value) pairs (with optional label).
     *
     * @return list<array{set: list<array{kind:string, value:string, label?:string|null}>, label: string}>
     */
    private function casesGenerator(): array
    {
        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $size = mt_rand(1, 8);
            $set = $this->randomDistinctSet($size);
            $cases[] = ['set' => $set, 'label' => "random#{$i} size=" . count($set)];
        }

        // Edge: minimum size (1) — single state. The validator requires `min:1`,
        // so this is the smallest input that the endpoint accepts.
        $cases[] = [
            'set' => [['kind' => ServiceArea::KIND_STATE, 'value' => 'MD', 'label' => 'Maryland']],
            'label' => 'edge: min-size-1 single state',
        ];

        // Edge: single kind only — three states, no regions/areas. Catches a regression
        // where the matcher/persister incorrectly assumes diverse kinds.
        $cases[] = [
            'set' => [
                ['kind' => ServiceArea::KIND_STATE, 'value' => 'MD'],
                ['kind' => ServiceArea::KIND_STATE, 'value' => 'VA'],
                ['kind' => ServiceArea::KIND_STATE, 'value' => 'NY'],
            ],
            'label' => 'edge: single-kind state x3',
        ];

        // Edge: one of each kind — exercises the full kind enum in one request.
        $cases[] = [
            'set' => [
                ['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast'],
                ['kind' => ServiceArea::KIND_STATE, 'value' => 'MD'],
                ['kind' => ServiceArea::KIND_AREA, 'value' => 'DC Metro'],
            ],
            'label' => 'edge: one of each kind',
        ];

        // Edge: large mixed set — multiple values across all three kinds, ordered
        // intentionally so a stable-sort regression (where order matters) would
        // visibly differ from the round-trip result.
        $cases[] = [
            'set' => [
                ['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast'],
                ['kind' => ServiceArea::KIND_REGION, 'value' => 'Pacific'],
                ['kind' => ServiceArea::KIND_STATE, 'value' => 'MD'],
                ['kind' => ServiceArea::KIND_STATE, 'value' => 'CA'],
                ['kind' => ServiceArea::KIND_STATE, 'value' => 'NY'],
                ['kind' => ServiceArea::KIND_AREA, 'value' => 'DC Metro'],
                ['kind' => ServiceArea::KIND_AREA, 'value' => 'Bay Area'],
                ['kind' => ServiceArea::KIND_AREA, 'value' => 'NYC Metro'],
            ],
            'label' => 'edge: large mixed set',
        ];

        return $cases;
    }

    /**
     * Draw `$size` distinct (kind, value) pairs from VALUE_POOL.
     *
     * @return list<array{kind:string, value:string}>
     */
    private function randomDistinctSet(int $size): array
    {
        $pool = [];
        foreach (self::VALUE_POOL as $kind => $values) {
            foreach ($values as $value) {
                $pool[] = ['kind' => $kind, 'value' => $value];
            }
        }
        shuffle($pool);

        return array_values(array_slice($pool, 0, $size));
    }

    /**
     * The property: round-trip + order independence + idempotence.
     *
     * Validates: Requirements 10.1, 10.4
     */
    public function test_service_area_assignment_round_trips_regardless_of_order_and_is_idempotent(): void
    {
        foreach ($this->casesGenerator() as $i => $case) {
            // Reset cache state between iterations so any side-channel cache writes
            // from a previous iteration's assign cannot bleed into this one. Each
            // iteration also creates a fresh photographer and asserts pivot counts
            // scoped by `user_id`, so cross-iteration state cannot taint results.
            Cache::flush();

            $expected = $this->keySet($case['set']);
            $context = "iteration {$i} ({$case['label']}, expected=[" . implode(',', $expected) . '])';

            // Setup: a fresh photographer (no existing service areas) and a fresh
            // admin actor with the role required by the route's middleware
            // (`auth:sanctum` + `role:admin,...`). Sanctum::actingAs is the same
            // helper used by the existing ServiceAreaAssignmentTest.
            $photographer = $this->photographer();
            Sanctum::actingAs($this->admin());

            // Two independent shuffles of the SAME set. Sending shuffle #1 first
            // and shuffle #2 second exercises both order independence (the two
            // shuffles must yield the same persisted set) and idempotence (the
            // second call must not change anything).
            $firstOrder = $this->shuffleCopy($case['set']);
            $secondOrder = $this->shuffleCopy($case['set']);

            // ----------------------------------------------------------------
            // (R, O) First assign — round-trip equality with a shuffled input.
            // ----------------------------------------------------------------
            $response = $this->postJson(
                "/api/admin/photographers/{$photographer->id}/service-areas",
                ['service_areas' => $firstOrder]
            );
            $response->assertOk();

            $afterFirst = $this->fetchAssignedSet($photographer);

            // (R) The persisted (kind, value) set, projected to sorted "kind:value"
            // keys, must equal the input set as a set — independent of insertion order.
            $this->assertSame(
                $expected,
                $afterFirst,
                "[R] After first assign, persisted (kind,value) set must equal input set for {$context}"
            );

            // (R) No duplicates: the pivot must contain exactly |S| rows for this user.
            $pivotCountFirst = \DB::table('photographer_service_areas')
                ->where('user_id', $photographer->id)
                ->count();
            $this->assertSame(
                count($expected),
                $pivotCountFirst,
                "[R] Pivot must contain exactly one row per (kind,value) after first assign for {$context}"
            );

            // ----------------------------------------------------------------
            // (I) Second assign — same set, different shuffle. Must be idempotent.
            // ----------------------------------------------------------------
            $response = $this->postJson(
                "/api/admin/photographers/{$photographer->id}/service-areas",
                ['service_areas' => $secondOrder]
            );
            $response->assertOk();

            $afterSecond = $this->fetchAssignedSet($photographer);

            // (I) Re-assigning S yields exactly S — no rows added, none removed.
            $this->assertSame(
                $expected,
                $afterSecond,
                "[I] After re-assigning the same set, persisted set must remain exactly the same for {$context}"
            );

            // (I) syncWithoutDetaching must not duplicate pivot rows on the second call.
            $pivotCountSecond = \DB::table('photographer_service_areas')
                ->where('user_id', $photographer->id)
                ->count();
            $this->assertSame(
                count($expected),
                $pivotCountSecond,
                "[I] Re-assignment must not duplicate pivot rows for {$context}"
            );

            // (O) The set is identical after two different shuffles of the same input.
            // This follows from (R) + (I) but is asserted explicitly so a regression
            // where shuffle order leaks into the result is caught with a dedicated message.
            $this->assertSame(
                $afterFirst,
                $afterSecond,
                "[O] Two independent shuffles of the same set must yield the same persisted set for {$context}"
            );
        }
    }

    /**
     * Read the photographer's persisted service areas as a sorted, deduped set of
     * "kind:value" keys. Sorting + dedup makes the assertion order-independent and
     * makes accidental pivot duplicates visible (the cardinality check still uses
     * the raw row count from the pivot table).
     *
     * @return list<string>
     */
    private function fetchAssignedSet(User $photographer): array
    {
        return $photographer->fresh()->serviceAreas()->get()
            ->map(fn (ServiceArea $area) => $area->kind . ':' . $area->value)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Project an input set to the same sorted, deduped "kind:value" representation
     * we read back. Lets the assertion compare apples to apples regardless of the
     * order the admin sent the areas in.
     *
     * @param  list<array{kind:string, value:string, label?:string|null}>  $set
     * @return list<string>
     */
    private function keySet(array $set): array
    {
        return collect($set)
            ->map(fn (array $area) => $area['kind'] . ':' . $area['value'])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Return a shuffled copy of the given set without mutating the original.
     *
     * @param  list<array{kind:string, value:string, label?:string|null}>  $set
     * @return list<array{kind:string, value:string, label?:string|null}>
     */
    private function shuffleCopy(array $set): array
    {
        $copy = $set;
        shuffle($copy);

        return $copy;
    }
}
