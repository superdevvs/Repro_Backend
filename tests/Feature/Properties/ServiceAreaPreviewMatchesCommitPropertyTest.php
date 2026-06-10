<?php

namespace Tests\Feature\Properties;

use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 5: Preview matches the committed
 * result and persists nothing.
 *
 * Validates: Requirements 10.3, 10.5
 *
 * For an arbitrary photographer/area distribution P, an arbitrary
 * (kind ∈ {region, state, area}, value) filter F, and an arbitrary target
 * user T (a photographer with no service areas yet), the following sub-properties
 * hold universally for the controller pair
 * `POST /api/admin/assignments/preview` and `POST /api/admin/assignments/commit`
 * (which share the same `runMatch` step before commit persists in a
 * transaction):
 *
 *   (E) **Preview equals commit's photographer set (AC 10.3)** — the set of
 *       photographer ids returned by `preview(F)` equals the set of
 *       photographer ids returned by `commit(F, T)`. Both are the result of the
 *       SAME match path, computed pre-persistence, so the previewed match must
 *       be exactly what commit reports.
 *
 *   (N) **Preview persists nothing (AC 10.5)** — the row count of
 *       `photographer_service_areas` after a `preview` call equals the row
 *       count taken immediately before the call. preview MUST NOT write to the
 *       pivot, the `service_areas` table, or any other persisted store.
 *
 *   (C) **Commit persists exactly one new pivot row for the target (AC 10.4)** —
 *       after a `commit(F, T)` against a target that did not yet have F, the
 *       pivot contains exactly one additional row, and that row links T to a
 *       `service_areas` record whose (kind, value) is exactly F. No other
 *       photographer's pivot rows change.
 *
 * No PHP property-based-testing library is installed, so this test follows the
 * same convention as the sibling round-trip property test: 20 randomized
 * scenarios (random size, random per-photographer area subsets, random filter,
 * fresh target) plus four deterministic edge cases (filter that matches no
 * photographer, filter that matches all photographers, filter that matches
 * exactly one photographer, and a mixed-kind population). The same universal
 * property must hold for every input.
 */
class ServiceAreaPreviewMatchesCommitPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 20 randomized iterations. */
    private const RANDOM_ITERATIONS = 20;

    /**
     * Fixture value pool per kind. Mirrors the round-trip property test's pool
     * so generators across the suite share the same realistic admin-input shape.
     *
     * @var array<string, list<string>>
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
     * The property: preview = commit on photographers, preview persists nothing,
     * and commit persists exactly one new pivot row for the target.
     *
     * Validates: Requirements 10.3, 10.5
     */
    public function test_preview_matches_commit_and_persists_nothing(): void
    {
        foreach ($this->casesGenerator() as $i => $case) {
            // Reset cache between iterations so any side-channel cache writes
            // from a previous iteration cannot bleed into this one. Each
            // iteration also creates fresh photographers, a fresh target, and a
            // fresh admin actor, and asserts row counts taken immediately
            // before/after each call so cross-iteration state cannot taint the
            // before/after deltas.
            Cache::flush();

            $context = "iteration {$i} ({$case['label']})";

            // --- Setup ---------------------------------------------------------
            // Build a population of photographers, each carrying a distinct
            // subset of (kind, value) service areas drawn from VALUE_POOL.
            $photographers = $this->buildPhotographerPopulation($case['photographer_specs']);
            $filter = $case['filter'];

            // Target is a fresh photographer with NO service areas attached yet,
            // and is intentionally separate from the matched population so that
            // commit always adds exactly one new pivot row for the target. This
            // makes (C) a clean +1 assertion regardless of the filter outcome.
            $target = $this->photographer();
            $this->assertSame(
                0,
                $target->serviceAreas()->count(),
                "Setup invariant: target must start with 0 service areas for {$context}"
            );

            // Snapshot pre-call state. The assertions below compare pivot row
            // counts to this snapshot to detect any persistence side effect of
            // preview (must be zero) and to verify commit's exactly-one-new-row
            // contract.
            $pivotBefore = DB::table('photographer_service_areas')->count();
            $serviceAreasBefore = DB::table('service_areas')->count();
            $perUserPivotBefore = $this->perUserPivotCounts();

            Sanctum::actingAs($this->admin());

            // --- (N) Preview persists nothing ---------------------------------
            $previewResponse = $this->postJson('/api/admin/assignments/preview', [
                'service_area_kind' => $filter['kind'],
                'service_area_value' => $filter['value'],
            ]);
            $previewResponse->assertOk();
            $previewResponse->assertJsonPath('preview', true);

            $pivotAfterPreview = DB::table('photographer_service_areas')->count();
            $serviceAreasAfterPreview = DB::table('service_areas')->count();

            // (N) The pivot table is the place commit would write to. preview
            // must leave it byte-for-byte unchanged.
            $this->assertSame(
                $pivotBefore,
                $pivotAfterPreview,
                "[N] Preview must not change photographer_service_areas row count for {$context}"
            );

            // (N) preview must also not create any new ServiceArea record (a
            // sneaky form of persistence the commit path performs via
            // firstOrCreate). The service_areas count must be unchanged.
            $this->assertSame(
                $serviceAreasBefore,
                $serviceAreasAfterPreview,
                "[N] Preview must not create any service_areas rows for {$context}"
            );

            // (N) No per-user pivot row count changes either — guards against a
            // regression that writes to a different user's pivot.
            $this->assertSame(
                $perUserPivotBefore,
                $this->perUserPivotCounts(),
                "[N] Preview must not change any user's pivot row counts for {$context}"
            );

            $previewIds = $this->extractPhotographerIds($previewResponse->json('photographers'));

            // --- Commit -------------------------------------------------------
            $commitResponse = $this->postJson('/api/admin/assignments/commit', [
                'service_area_kind' => $filter['kind'],
                'service_area_value' => $filter['value'],
                'user_id' => $target->id,
            ]);
            $commitResponse->assertOk();
            $commitResponse->assertJsonPath('committed', true);

            $commitIds = $this->extractPhotographerIds($commitResponse->json('photographers'));

            // (E) Preview's match set equals commit's match set (both computed
            // before commit's persistence step). Sets are compared by sorted ids
            // so insertion order in the response payload is irrelevant.
            $this->assertSame(
                $previewIds,
                $commitIds,
                "[E] Preview's photographer set must equal commit's photographer set for {$context}"
            );

            // --- (C) Commit persists exactly one new pivot row for target ----
            $pivotAfterCommit = DB::table('photographer_service_areas')->count();
            $this->assertSame(
                $pivotBefore + 1,
                $pivotAfterCommit,
                "[C] Commit must add exactly one pivot row for {$context}"
            );

            // The new pivot row must link the target user to a service_areas
            // record whose (kind, value) is exactly the filter — i.e. the
            // commit assignment is for (target, filter.kind, filter.value).
            $serviceAreaId = DB::table('service_areas')
                ->where('kind', $filter['kind'])
                ->where('value', $filter['value'])
                ->value('id');
            $this->assertNotNull(
                $serviceAreaId,
                "[C] Commit must ensure a service_areas row for (kind, value) exists for {$context}"
            );
            $this->assertDatabaseHas('photographer_service_areas', [
                'user_id' => $target->id,
                'service_area_id' => $serviceAreaId,
            ]);

            // Per-user row counts: the target gains exactly one row; every
            // other user's pivot count is unchanged. Catches a regression that
            // accidentally attaches the area to the matched photographers
            // instead of the target.
            $perUserPivotAfter = $this->perUserPivotCounts();
            $expectedPerUser = $perUserPivotBefore;
            $expectedPerUser[$target->id] = ($expectedPerUser[$target->id] ?? 0) + 1;
            ksort($expectedPerUser);
            ksort($perUserPivotAfter);
            $this->assertSame(
                $expectedPerUser,
                $perUserPivotAfter,
                "[C] Commit must add exactly one pivot row to the target only for {$context}"
            );
        }
    }

    /**
     * Generate the test cases: 20 randomized scenarios + 4 deterministic edges.
     *
     * Each case yields:
     *   - photographer_specs: list of per-photographer area lists drawn from VALUE_POOL
     *   - filter: ['kind' => ..., 'value' => ...]
     *   - label: a human-readable identifier surfaced in failure messages
     *
     * The randomized scenarios deliberately span small/large populations and
     * filter outcomes (zero/some/all matching photographers); the deterministic
     * edge cases pin down boundary outcomes the random draws may not always hit.
     *
     * @return list<array{
     *     photographer_specs: list<list<array{kind:string, value:string}>>,
     *     filter: array{kind:string, value:string},
     *     label: string,
     * }>
     */
    private function casesGenerator(): array
    {
        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $photographerCount = mt_rand(2, 8);
            $photographerSpecs = [];
            for ($p = 0; $p < $photographerCount; $p++) {
                $areaCount = mt_rand(0, 4);
                $photographerSpecs[] = $this->randomDistinctSet($areaCount);
            }
            $cases[] = [
                'photographer_specs' => $photographerSpecs,
                'filter' => $this->randomFilter(),
                'label' => "random#{$i} N={$photographerCount}",
            ];
        }

        // Edge: filter matches no photographer at all (every photographer's
        // serviceAreas list is empty). preview/commit must both return an
        // empty photographers set, preview must persist nothing, and commit
        // must still add one row for the target — the empty match case must
        // not collapse into a no-op.
        $cases[] = [
            'photographer_specs' => [[], [], []],
            'filter' => ['kind' => ServiceArea::KIND_STATE, 'value' => 'MD'],
            'label' => 'edge: filter matches zero photographers',
        ];

        // Edge: filter matches every photographer in the population. The
        // preview = commit equality and "+1 pivot row for target" properties
        // must still hold even when the match set is large.
        $cases[] = [
            'photographer_specs' => [
                [['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast']],
                [['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast']],
                [['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast']],
            ],
            'filter' => ['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast'],
            'label' => 'edge: filter matches all photographers',
        ];

        // Edge: filter matches exactly one photographer in the population.
        // Catches a regression where the match step over-/under-includes
        // by one.
        $cases[] = [
            'photographer_specs' => [
                [['kind' => ServiceArea::KIND_STATE, 'value' => 'MD']],
                [['kind' => ServiceArea::KIND_STATE, 'value' => 'VA']],
                [['kind' => ServiceArea::KIND_STATE, 'value' => 'CA']],
            ],
            'filter' => ['kind' => ServiceArea::KIND_STATE, 'value' => 'MD'],
            'label' => 'edge: filter matches exactly one photographer',
        ];

        // Edge: a mixed-kind population so the controller's match path sees
        // photographers carrying region+state+area combinations simultaneously.
        $cases[] = [
            'photographer_specs' => [
                [
                    ['kind' => ServiceArea::KIND_REGION, 'value' => 'Pacific'],
                    ['kind' => ServiceArea::KIND_STATE, 'value' => 'CA'],
                    ['kind' => ServiceArea::KIND_AREA, 'value' => 'Bay Area'],
                ],
                [
                    ['kind' => ServiceArea::KIND_REGION, 'value' => 'Northeast'],
                    ['kind' => ServiceArea::KIND_STATE, 'value' => 'NY'],
                ],
                [
                    ['kind' => ServiceArea::KIND_AREA, 'value' => 'DC Metro'],
                ],
            ],
            'filter' => ['kind' => ServiceArea::KIND_AREA, 'value' => 'Bay Area'],
            'label' => 'edge: mixed-kind population',
        ];

        return $cases;
    }

    /**
     * Materialize a population of photographers, attaching each one's specified
     * (kind, value) service areas via syncWithoutDetaching on the pivot. Returns
     * the created photographer User models in spec order.
     *
     * @param  list<list<array{kind:string, value:string}>>  $specs
     * @return list<User>
     */
    private function buildPhotographerPopulation(array $specs): array
    {
        $photographers = [];
        foreach ($specs as $areaList) {
            $photographer = $this->photographer();
            $areaIds = [];
            foreach ($areaList as $area) {
                $serviceArea = ServiceArea::firstOrCreate(
                    ['kind' => $area['kind'], 'value' => $area['value']],
                );
                $areaIds[] = $serviceArea->id;
            }
            if ($areaIds !== []) {
                $photographer->serviceAreas()->syncWithoutDetaching($areaIds);
            }
            $photographers[] = $photographer;
        }

        return $photographers;
    }

    /**
     * Draw `$size` distinct (kind, value) pairs from VALUE_POOL — the same
     * generator shape the round-trip property test uses for assignment input.
     *
     * @return list<array{kind:string, value:string}>
     */
    private function randomDistinctSet(int $size): array
    {
        if ($size <= 0) {
            return [];
        }

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
     * Draw a random filter (kind, value) from VALUE_POOL.
     *
     * @return array{kind:string, value:string}
     */
    private function randomFilter(): array
    {
        $kinds = array_keys(self::VALUE_POOL);
        $kind = $kinds[array_rand($kinds)];
        $value = self::VALUE_POOL[$kind][array_rand(self::VALUE_POOL[$kind])];

        return ['kind' => $kind, 'value' => $value];
    }

    /**
     * Extract photographer ids from a controller `photographers` payload as a
     * sorted array of ints. Sorting makes the set comparison order-independent.
     *
     * @param  array<int, array<string, mixed>>|null  $photographers
     * @return list<int>
     */
    private function extractPhotographerIds(?array $photographers): array
    {
        $ids = collect($photographers ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        sort($ids);

        return $ids;
    }

    /**
     * Per-user pivot row counts as `[user_id => count]`, used to assert that
     * preview changes nothing and commit changes only the target's count by 1.
     *
     * @return array<int, int>
     */
    private function perUserPivotCounts(): array
    {
        return DB::table('photographer_service_areas')
            ->selectRaw('user_id, COUNT(*) as c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }
}
