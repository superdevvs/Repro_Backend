<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Six-shoots / six-services preservation test (MANDATORY, tasks.md 11.1).
 *
 * Feature: booking-scheduling-fixes, Property 16
 *
 * Property 16 — The change set preserves the existing scheduling baseline:
 * The six existing shoots and six services (captured read-only in task 1.9 as the
 * immutable fixture tests/Fixtures/scheduling_baseline.json) must be unchanged by
 * this bugfix change set. Specifically: shoot-level times, per-service times,
 * service assignments, and the stored timezone are preserved; no record is
 * reseeded, deleted, or silently modified; and no destructive/reseeding migration
 * touching the shoots / shoot_service tables is introduced.
 *
 * Validation strategy (chosen for this repo):
 * The PHPUnit suite runs against a fresh in-memory SQLite database (phpunit.xml:
 * DB_CONNECTION=sqlite, DB_DATABASE=:memory:) under RefreshDatabase, so the live
 * dev DB that the baseline was captured from is NOT visible to the test. A naive
 * re-read of the six shoots would therefore find nothing. As the task instructs,
 * for that test-DB setup we validate preservation by:
 *
 *   1. FIXTURE INTEGRITY — the immutable baseline is intact and well-formed
 *      (counts, key invariants: Shoot #1 07:00:00; Shoot #2 2D Floor Plan @ 09:30
 *      + HDR @ 11:30; all shoots timezone = null).
 *   2. CANONICAL-FORMAT INVARIANTS — every shoot time is stored unformatted
 *      (HH:mm or HH:mm:ss), every scheduled_at is canonical 'Y-m-d H:i:s', and
 *      stored timezone is null.
 *   3. STORAGE ROUND-TRIP — reconstructing the exact baseline through the real
 *      model/cast/storage layer and reading it back yields identical shoot-level
 *      times, per-service times, service assignments, and timezone (i.e. the
 *      persistence layer performs no silent modification), with counts unchanged
 *      (6 shoots / 6 services / 9 shoot_service rows — no reseed/delete).
 *   4. SCHEMA / MIGRATION PRESERVATION — after all migrations run, the canonical
 *      shoots/shoot_service columns still exist, and no migration drops or
 *      truncates those tables in its up() path (no destructive change-set
 *      migration).
 */
class SixShootsSixServicesPreservationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Decoded baseline fixture captured in task 1.9.
     *
     * @var array<string, mixed>
     */
    protected array $baseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseline = $this->loadBaseline();
    }

    /**
     * 1. The immutable baseline fixture is intact and well-formed: the captured
     *    counts (6 shoots / 6 services / 9 assignments) agree with the data, and
     *    the canonical anchor values are present and exactly as captured.
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function baseline_fixture_is_intact_and_well_formed(): void
    {
        $meta = $this->baseline['_meta'];
        $services = $this->baseline['services'];
        $shoots = $this->baseline['shoots'];

        // Captured counts agree with the actual arrays (no silent edit to the file).
        $this->assertSame(6, $meta['shoot_count']);
        $this->assertSame(6, $meta['service_count']);
        $this->assertSame(9, $meta['shoot_service_assignment_count']);
        $this->assertCount(6, $shoots, 'Baseline must describe exactly 6 shoots.');
        $this->assertCount(6, $services, 'Baseline must describe exactly 6 services.');
        $this->assertSame(
            9,
            $this->totalAssignments($shoots),
            'Baseline must describe exactly 9 shoot_service assignments.'
        );

        // Services 1-6 are present with their canonical names/ids.
        $serviceNames = collect($services)->pluck('name', 'service_id')->all();
        $this->assertSame('HDR Photography', $serviceNames[1]);
        $this->assertSame('2D Floor Plan', $serviceNames[2]);
        $this->assertCount(6, $serviceNames);

        // Shoot #1 canonical time anchor.
        $shoot1 = $this->shootById(1);
        $this->assertSame('07:00:00', $shoot1['time']);
        $this->assertSame('2026-06-18 07:00:00', $shoot1['scheduled_at']);
        $this->assertNull($shoot1['timezone']);

        // Shoot #2 per-service time anchors: 2D Floor Plan @ 09:30, HDR @ 11:30.
        $shoot2 = $this->shootById(2);
        $this->assertSame('09:30', $shoot2['time']);
        $assignments = collect($shoot2['service_assignments'])->keyBy('service_id');
        $this->assertSame('2026-06-20 09:30:00', $assignments[2]['scheduled_at']); // 2D Floor Plan
        $this->assertSame('2026-06-20 11:30:00', $assignments[1]['scheduled_at']); // HDR
    }

    /**
     * 2. Canonical-format invariants hold for every shoot in the baseline: times
     *    are stored unformatted (HH:mm or HH:mm:ss), scheduled_at is canonical
     *    'Y-m-d H:i:s', and the stored timezone is null on every shoot.
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function baseline_times_use_canonical_unformatted_storage(): void
    {
        foreach ($this->baseline['shoots'] as $shoot) {
            $this->assertMatchesRegularExpression(
                '/^\d{2}:\d{2}(:\d{2})?$/',
                $shoot['time'],
                "Shoot #{$shoot['shoot_id']} time must be canonical HH:mm or HH:mm:ss (unformatted)."
            );
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                $shoot['scheduled_at'],
                "Shoot #{$shoot['shoot_id']} scheduled_at must be canonical 'Y-m-d H:i:s'."
            );
            $this->assertNull(
                $shoot['timezone'],
                "Shoot #{$shoot['shoot_id']} stored timezone must remain null (never rewritten)."
            );

            foreach ($shoot['service_assignments'] as $assignment) {
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                    $assignment['scheduled_at'],
                    "Per-service scheduled_at on shoot #{$shoot['shoot_id']} must be canonical."
                );
            }
        }
    }

    /**
     * 3. Reconstructing the exact baseline through the real model/cast/storage
     *    layer and reading it back yields identical shoot-level times, per-service
     *    times, service assignments, and timezone — proving the persistence layer
     *    performs no silent modification — and the counts are unchanged (no
     *    reseed/delete).
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function reconstructed_baseline_round_trips_without_modification(): void
    {
        [$serviceMap, $photographerId] = $this->seedBaselineIntoDatabase();

        // Counts unchanged: no reseed, no delete, no extra rows introduced.
        $this->assertSame(6, Shoot::count(), 'Exactly 6 shoots must exist (no reseed/delete).');
        $this->assertSame(6, Service::count(), 'Exactly 6 services must exist (no reseed/delete).');
        $this->assertSame(
            9,
            DB::table('shoot_service')->count(),
            'Exactly 9 shoot_service assignments must exist (no reseed/delete).'
        );

        // Map a real service id back to its baseline (fixture) service id.
        $fixtureIdForService = array_flip($serviceMap);

        foreach ($this->baseline['shoots'] as $expected) {
            $shoot = Shoot::where('time', $expected['time'])
                ->where('scheduled_at', $expected['scheduled_at'])
                ->firstOrFail();

            // Shoot-level time, scheduled_at, and stored timezone are unchanged.
            $this->assertSame(
                $expected['time'],
                $shoot->getRawOriginal('time'),
                "Shoot #{$expected['shoot_id']} time must round-trip unchanged."
            );
            $this->assertSame(
                $expected['scheduled_at'],
                $shoot->scheduled_at?->format('Y-m-d H:i:s'),
                "Shoot #{$expected['shoot_id']} scheduled_at must round-trip unchanged."
            );
            $this->assertNull(
                $shoot->timezone,
                "Shoot #{$expected['shoot_id']} timezone must remain null after round-trip."
            );

            // Service assignments + per-service scheduled_at are unchanged. Read the
            // raw pivot rows and translate stored service ids back to baseline ids.
            $pivotRows = DB::table('shoot_service')->where('shoot_id', $shoot->id)->get();
            $this->assertCount(
                count($expected['service_assignments']),
                $pivotRows,
                "Shoot #{$expected['shoot_id']} must keep exactly its baseline assignments."
            );

            $actualAssignments = $pivotRows
                ->map(fn ($row) => [
                    'service_id' => $fixtureIdForService[$row->service_id],
                    'scheduled_at' => $this->canonical($row->scheduled_at),
                    'photographer_id' => (int) $row->photographer_id,
                ])
                ->sortBy('service_id')
                ->values()
                ->all();

            $expectedAssignments = collect($expected['service_assignments'])
                ->map(fn ($assignment) => [
                    'service_id' => $assignment['service_id'],
                    'scheduled_at' => $assignment['scheduled_at'],
                    'photographer_id' => $photographerId,
                ])
                ->sortBy('service_id')
                ->values()
                ->all();

            $this->assertEquals(
                $expectedAssignments,
                $actualAssignments,
                "Shoot #{$expected['shoot_id']} service assignments / per-service times must round-trip unchanged."
            );
        }
    }

    /**
     * 4. The change set introduces no destructive/reseeding migration touching the
     *    shoots / shoot_service tables: after all migrations run the canonical
     *    columns still exist, and no migration drops or truncates those tables in
     *    its up() path.
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function no_destructive_migration_touches_the_scheduling_tables(): void
    {
        // Canonical schema survived every migration (RefreshDatabase ran them all).
        $this->assertTrue(Schema::hasTable('shoots'));
        $this->assertTrue(Schema::hasTable('shoot_service'));

        foreach (['time', 'scheduled_at', 'scheduled_date', 'timezone'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('shoots', $column),
                "Canonical shoots.{$column} column must be preserved."
            );
        }
        foreach (['shoot_id', 'service_id', 'scheduled_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('shoot_service', $column),
                "Canonical shoot_service.{$column} column must be preserved."
            );
        }

        // No migration drops or truncates the scheduling tables in its up() path.
        foreach ($this->migrationFiles() as $file) {
            $up = $this->upSection(file_get_contents($file));
            $name = basename($file);

            foreach (['shoots', 'shoot_service'] as $table) {
                $this->assertStringNotContainsString(
                    "dropIfExists('{$table}')",
                    $up,
                    "Migration {$name} must not drop the {$table} table (up path)."
                );
                $this->assertStringNotContainsString(
                    "Schema::drop('{$table}')",
                    $up,
                    "Migration {$name} must not drop the {$table} table (up path)."
                );
                $this->assertStringNotContainsString(
                    "DB::table('{$table}')->truncate()",
                    $up,
                    "Migration {$name} must not truncate the {$table} table (up path)."
                );
            }
        }
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * Reconstruct the baseline (6 services, 6 shoots, 9 assignments) into the fresh
     * test DB through the real models. Returns [fixtureServiceId => realServiceId]
     * and the real photographer id used for every assignment.
     *
     * @return array{0: array<int, int>, 1: int}
     */
    protected function seedBaselineIntoDatabase(): array
    {
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'baseline-photographer@test.com',
        ]);

        $serviceMap = [];
        foreach ($this->baseline['services'] as $service) {
            $created = Service::factory()->create([
                'name' => $service['name'],
                'price' => 100.00,
            ]);
            $serviceMap[$service['service_id']] = $created->id;
        }

        // A default service id for the legacy shoots.service_id column. Pointing
        // it at an existing baseline service keeps the factory from creating any
        // extra Service rows, so the "no reseed/delete" counts stay exact.
        $defaultServiceId = $serviceMap[array_key_first($serviceMap)];

        foreach ($this->baseline['shoots'] as $shoot) {
            $primaryServiceId = $shoot['service_assignments'][0]['service_id'] ?? null;

            $created = Shoot::factory()->create([
                'time' => $shoot['time'],
                'scheduled_at' => $shoot['scheduled_at'],
                'scheduled_date' => $shoot['scheduled_date'],
                'timezone' => $shoot['timezone'],
                'photographer_id' => $photographer->id,
                'service_id' => $primaryServiceId !== null
                    ? $serviceMap[$primaryServiceId]
                    : $defaultServiceId,
                'status' => Shoot::STATUS_SCHEDULED,
                'workflow_status' => Shoot::STATUS_SCHEDULED,
            ]);

            foreach ($shoot['service_assignments'] as $assignment) {
                $created->services()->attach($serviceMap[$assignment['service_id']], [
                    'price' => 100,
                    'quantity' => 1,
                    'photographer_pay' => 30,
                    'photographer_id' => $photographer->id,
                    'scheduled_at' => $assignment['scheduled_at'],
                ]);
            }
        }

        return [$serviceMap, $photographer->id];
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadBaseline(): array
    {
        $path = base_path('tests/Fixtures/scheduling_baseline.json');
        $this->assertFileExists($path, 'The read-only scheduling baseline fixture must exist.');

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'The baseline fixture must be valid JSON.');

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function shootById(int $id): array
    {
        foreach ($this->baseline['shoots'] as $shoot) {
            if ($shoot['shoot_id'] === $id) {
                return $shoot;
            }
        }

        $this->fail("Baseline shoot #{$id} not found.");
    }

    /**
     * @param array<int, array<string, mixed>> $shoots
     */
    protected function totalAssignments(array $shoots): int
    {
        return collect($shoots)->sum(fn ($shoot) => count($shoot['service_assignments']));
    }

    /**
     * Normalize a stored datetime string to the canonical 'Y-m-d H:i:s' form for
     * comparison (SQLite may store with or without fractional/extra precision).
     */
    protected function canonical(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    /**
     * @return array<int, string>
     */
    protected function migrationFiles(): array
    {
        return glob(database_path('migrations/*.php')) ?: [];
    }

    /**
     * Return the up() portion of a migration's source (everything before the
     * down() definition) so reversible drops in down() don't trigger false
     * positives.
     */
    protected function upSection(string $source): string
    {
        $pos = stripos($source, 'function down');

        return $pos === false ? $source : substr($source, 0, $pos);
    }
}
