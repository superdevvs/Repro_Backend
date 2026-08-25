<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\CubiCasaService;
use App\Support\LockedWrite;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * cubicasa:resync-pending was failing roughly one scheduled run in five with
 * "database is locked", aborting partway through and taking the missing-order
 * backfill down with it.
 *
 * The cause was not a missing PRAGMA. Production already runs WAL with
 * busy_timeout=5000. The command iterated shoots over an open cursor and wrote
 * to each one inside that loop, which keeps a SQLite read snapshot open across
 * the writes. When the database queue worker committed mid-loop, the writing
 * connection got a snapshot conflict, and SQLite returns that immediately
 * rather than waiting, so busy_timeout never applied.
 *
 * These tests pin three things: that a genuine SQLite write conflict is
 * recognised and survivable, that persistent contention still fails loudly
 * instead of being swallowed, and that one unwritable shoot no longer costs us
 * the rest of the run.
 *
 * The contention in the first two tests is real. Two live connections to a real
 * SQLite file are used, one holding an exclusive transaction, and the error
 * under assertion is the one SQLite itself raises.
 */
class CubiCasaResyncLockResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const ORDER_ID = '9ba65f04-3ee2-4de9-a098-ece787ceee57';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.owner_email', 'orders@reprophotos.com');
        Storage::fake('public');
    }

    // ------------------------------------------------- real SQLite contention

    public function test_a_genuine_sqlite_write_conflict_is_recognised_as_contention(): void
    {
        [$path, $holder, $writer] = $this->openContendedDatabase();

        try {
            // A real exclusive transaction on another connection.
            $holder->exec('BEGIN EXCLUSIVE');
            $holder->exec("INSERT INTO widgets (name) VALUES ('held')");

            $caught = null;
            try {
                $writer->exec("INSERT INTO widgets (name) VALUES ('blocked')");
            } catch (PDOException $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught, 'SQLite should refuse this write while another connection holds the lock');
            $this->assertStringContainsStringIgnoringCase('database is locked', $caught->getMessage());
            $this->assertTrue(
                LockedWrite::isLockContention($caught),
                'the real SQLite error must be classified as retryable contention'
            );
        } finally {
            $this->closeContendedDatabase($path, $holder, $writer);
        }
    }

    public function test_a_genuine_sqlite_write_conflict_is_retried_and_succeeds_once_the_lock_clears(): void
    {
        [$path, $holder, $writer] = $this->openContendedDatabase();

        try {
            $holder->exec('BEGIN EXCLUSIVE');
            $holder->exec("INSERT INTO widgets (name) VALUES ('held')");

            $attempts = 0;
            $released = false;

            $result = LockedWrite::run(function () use ($writer, $holder, &$attempts, &$released) {
                $attempts++;

                // Let the real lock go partway through, the way a competing
                // writer finishing its transaction would.
                if ($attempts === 3 && !$released) {
                    $holder->exec('COMMIT');
                    $released = true;
                }

                $writer->exec("INSERT INTO widgets (name) VALUES ('written')");

                return 'ok';
            }, 'test.real-lock', 5);

            $this->assertSame('ok', $result);
            $this->assertSame(3, $attempts, 'should have retried until the real lock cleared');

            $rows = $writer->query("SELECT COUNT(*) FROM widgets WHERE name = 'written'")->fetchColumn();
            $this->assertSame(1, (int) $rows, 'the write must land exactly once, not once per attempt');
        } finally {
            $this->closeContendedDatabase($path, $holder, $writer);
        }
    }

    public function test_persistent_contention_rethrows_the_original_error_instead_of_hiding_it(): void
    {
        [$path, $holder, $writer] = $this->openContendedDatabase();

        try {
            $holder->exec('BEGIN EXCLUSIVE');
            $holder->exec("INSERT INTO widgets (name) VALUES ('held')");

            $attempts = 0;

            try {
                LockedWrite::run(function () use ($writer, &$attempts) {
                    $attempts++;
                    $writer->exec("INSERT INTO widgets (name) VALUES ('never')");
                }, 'test.persistent-lock', 3);

                $this->fail('a lock that never clears must not be reported as success');
            } catch (PDOException $e) {
                $this->assertStringContainsStringIgnoringCase('database is locked', $e->getMessage());
            }

            $this->assertSame(3, $attempts, 'retries are bounded, not infinite');

            $holder->exec('ROLLBACK');
            $rows = $writer->query("SELECT COUNT(*) FROM widgets WHERE name = 'never'")->fetchColumn();
            $this->assertSame(0, (int) $rows, 'no partial write may survive a failed retry sequence');
        } finally {
            $this->closeContendedDatabase($path, $holder, $writer);
        }
    }

    public function test_an_error_that_is_not_contention_is_never_retried(): void
    {
        // A constraint violation is a real failure. Retrying it would turn a
        // fast, clear error into a slow, confusing one.
        $attempts = 0;

        try {
            LockedWrite::run(function () use (&$attempts) {
                $attempts++;
                throw new RuntimeException('UNIQUE constraint failed: shoots.id');
            }, 'test.not-a-lock', 4);

            $this->fail('the error should have propagated');
        } catch (RuntimeException $e) {
            $this->assertSame('UNIQUE constraint failed: shoots.id', $e->getMessage());
        }

        $this->assertSame(1, $attempts, 'a non-lock error must fail on the first attempt');
    }

    // ------------------------------------------- the service write boundary

    public function test_apply_shoot_data_survives_a_transient_lock_on_the_shoot_write(): void
    {
        $shoot = $this->flakyShoot();
        $shoot->failUntilAttempt = 2;

        $parsed = app(CubiCasaService::class)->parseOrderData($this->readyOrder());
        app(CubiCasaService::class)->applyShootData($shoot, $parsed);

        $this->assertSame(3, $shoot->saveCalls, 'two blocked attempts then a successful one');

        $fresh = Shoot::find($shoot->id);
        $this->assertSame('Ready', $fresh->cubicasa_status);
        $this->assertSame(self::ORDER_ID, $fresh->cubicasa_order_id);
        $this->assertSame(
            CubiCasaService::SYNC_STATUS_SUCCEEDED,
            $fresh->cubicasa_sync_status,
            'the state must be fully persisted, not half-written'
        );
    }

    public function test_apply_shoot_data_leaves_no_partial_state_when_the_lock_never_clears(): void
    {
        $shoot = $this->flakyShoot();
        $shoot->failUntilAttempt = PHP_INT_MAX;

        $parsed = app(CubiCasaService::class)->parseOrderData($this->readyOrder());

        try {
            app(CubiCasaService::class)->applyShootData($shoot, $parsed);
            $this->fail('a write that never lands must surface as a failure');
        } catch (QueryException $e) {
            $this->assertTrue(LockedWrite::isLockContention($e));
        }

        $fresh = Shoot::find($shoot->id);
        $this->assertNull($fresh->cubicasa_order_id, 'nothing may be persisted when every attempt was refused');
        $this->assertNull($fresh->cubicasa_status);
    }

    // ------------------------------------------------------- command behaviour

    public function test_one_unwritable_shoot_does_not_abort_the_rest_of_the_run(): void
    {
        // This is what made the failure expensive: an exception on any shoot
        // skipped every later shoot AND the missing-order backfill below it.
        Queue::fake();
        $this->fakeOrderEndpoint();

        $doomed = $this->linkedShoot('Draft', '11 Doomed Street');
        $healthy = $this->linkedShoot('Draft', '22 Healthy Street');

        $this->swapServiceThatFailsFor($doomed->id);

        $this->artisan('cubicasa:resync-pending')->assertFailed();

        $this->assertSame(
            'Ready',
            $healthy->fresh()->cubicasa_status,
            'the shoot after the failure must still have been reconciled'
        );
    }

    public function test_a_crashed_sync_does_not_leave_the_shoot_stuck_in_running(): void
    {
        // syncShoot() marks the shoot running before calling the provider. When
        // the write after that died, the shoot stayed "running" forever and
        // looked like an in-flight sync.
        Queue::fake();
        $this->fakeOrderEndpoint();

        $doomed = $this->linkedShoot('Draft', '11 Doomed Street');
        $this->swapServiceThatFailsFor($doomed->id);

        $this->artisan('cubicasa:resync-pending')->assertFailed();

        $fresh = $doomed->fresh();
        $this->assertNotSame(
            CubiCasaService::SYNC_STATUS_RUNNING,
            $fresh->cubicasa_sync_status,
            'a crashed sync must not leave the shoot marked running'
        );
        $this->assertSame(CubiCasaService::SYNC_STATUS_FAILED, $fresh->cubicasa_sync_status);
        $this->assertNotEmpty($fresh->cubicasa_last_sync_error);
    }

    public function test_a_pending_order_is_reconciled_and_its_state_persisted(): void
    {
        Queue::fake();
        $this->fakeOrderEndpoint();

        $shoot = $this->linkedShoot('Draft', '521 Brightfield Road');

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();

        $fresh = $shoot->fresh();
        $this->assertSame('Ready', $fresh->cubicasa_status);
        $this->assertSame('Tier3-LiDAR', $fresh->cubicasa_product_type);
        $this->assertSame(CubiCasaService::SYNC_STATUS_SUCCEEDED, $fresh->cubicasa_sync_status);
        $this->assertNotNull($fresh->cubicasa_last_synced_at);
        $this->assertNotEmpty($fresh->cubicasa_floorplans, 'deliverables must be recorded on the shoot');

        Queue::assertPushed(
            IngestCubiCasaAssetsJob::class,
            fn ($job) => $job->shootId === $shoot->id && !empty($job->floorplans)
        );
    }

    public function test_a_completed_order_keeps_its_floorplan_media_available(): void
    {
        Queue::fake();
        $this->fakeOrderEndpoint();

        $shoot = $this->linkedShoot('Draft', '521 Brightfield Road');

        // An asset already ingested from a previous delivery. The provider asset
        // key lives in metadata, not in a column of its own.
        $existing = $this->ingestedFloorplan($shoot, 'pdf_listing_dim_0', '521-merged-dim.pdf');

        $before = ShootFile::where('shoot_id', $shoot->id)->count();

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();

        $this->assertSame(
            $before,
            ShootFile::where('shoot_id', $shoot->id)->count(),
            'reconciliation must not disturb already delivered floorplan media'
        );

        $stillThere = ShootFile::find($existing->id);
        $this->assertNotNull($stillThere, 'the delivered floorplan must survive reconciliation');
        $this->assertSame('pdf_listing_dim_0', $stillThere->metadata['cubicasa_asset_key'] ?? null);
        $this->assertSame('floorplan', $stillThere->media_type);
        $this->assertNotEmpty($shoot->fresh()->cubicasa_floorplans);
    }

    public function test_repeated_reconciliation_creates_no_duplicate_order_or_media(): void
    {
        Queue::fake();
        $this->fakeOrderEndpoint();

        $shoot = $this->linkedShoot('Draft', '521 Brightfield Road');
        $originalOrderId = $shoot->cubicasa_order_id;

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        $this->artisan('cubicasa:resync-pending')->assertSuccessful();

        $fresh = $shoot->fresh();
        $this->assertSame($originalOrderId, $fresh->cubicasa_order_id, 'the order identity must be stable');

        $this->assertSame(
            1,
            Shoot::where('cubicasa_order_id', $originalOrderId)->count(),
            'one provider order must never map to several shoots'
        );

        // The backfill safety net must not order for an already linked shoot.
        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_order_creation_stays_idempotent_across_repeated_attempts(): void
    {
        Http::fake([
            self::BASE_URL.'/orders/draft' => Http::response([
                'id' => self::ORDER_ID,
                'info' => ['external_id' => null, 'status' => 'New', 'order_type' => 'Tier3-LiDAR'],
                'address' => ['full_address' => '521 Brightfield Road'],
            ], 200),
            self::BASE_URL.'/orders/*' => Http::response($this->readyOrder(), 200),
        ]);

        $shoot = $this->shootWithFloorPlan(['address' => '521 Brightfield Road']);

        $service = app(CubiCasaService::class);
        $service->createOrder($shoot->fresh(), null, 'test');
        $key = $shoot->fresh()->cubicasa_idempotency_key;
        $this->assertNotEmpty($key, 'a per-shoot idempotency key must be persisted');

        // A second attempt syncs the existing order rather than creating another.
        $service->createOrder($shoot->fresh(), null, 'test');

        $this->assertSame($key, $shoot->fresh()->cubicasa_idempotency_key, 'the key must be reused, not regenerated');
        $this->assertSame(
            1,
            Shoot::whereNotNull('cubicasa_order_id')->count(),
            'no duplicate order may be produced'
        );
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: string, 1: PDO, 2: PDO} */
    private function openContendedDatabase(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lockwrite') ?: throw new RuntimeException('no temp file');

        $dsn = 'sqlite:'.$path;
        $holder = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $writer = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $holder->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');

        // Fail fast instead of waiting, so the retry path is what is under test
        // rather than the driver-level wait.
        $holder->exec('PRAGMA busy_timeout = 0');
        $writer->exec('PRAGMA busy_timeout = 0');

        return [$path, $holder, $writer];
    }

    private function closeContendedDatabase(string $path, PDO &$holder, PDO &$writer): void
    {
        try {
            if ($holder->inTransaction()) {
                $holder->exec('ROLLBACK');
            }
        } catch (\Throwable) {
            // Nothing useful to do while tearing down.
        }

        $holder = null;
        $writer = null;

        foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function fakeOrderEndpoint(): void
    {
        Http::fake([
            self::BASE_URL.'/orders/*' => Http::response($this->readyOrder(), 200),
        ]);
    }

    /**
     * Replace the service with one that throws a real lock error for a single
     * shoot and behaves normally for every other shoot.
     */
    private function swapServiceThatFailsFor(int $shootId): void
    {
        $this->app->bind(CubiCasaService::class, fn () => new FailsForOneShootCubiCasaService($shootId));
    }

    private function readyOrder(): array
    {
        return [
            'id' => self::ORDER_ID,
            'info' => [
                'status' => 'Ready',
                'order_type' => 'Tier3-LiDAR',
                'external_id' => null,
                'product' => ['package_type' => 'plus', 'add_ons' => []],
            ],
            'address' => ['full_address' => '521 Brightfield Road, Lutherville Timonium, MD 21093'],
            'delivery_assets' => [
                'listing_floorplans' => [
                    'pdf_urls_dim' => ['https://s3.example.com/521-merged-dim.pdf'],
                    'jpg_urls_dim' => ['https://s3.example.com/floor-1-dim.jpg'],
                ],
                'home_report' => ['pdf_urls' => []],
            ],
        ];
    }

    private function floorPlanService(): Service
    {
        return Service::factory()->create(['name' => '2D Floor plans']);
    }

    private function shootWithFloorPlan(array $attributes = []): Shoot
    {
        $shoot = Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2),
        ], $attributes));

        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $this->floorPlanService()->id,
            'price' => 195,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh();
    }

    private function linkedShoot(string $status, string $address): Shoot
    {
        return $this->shootWithFloorPlan([
            'address' => $address,
            'cubicasa_order_id' => self::ORDER_ID,
            'cubicasa_status' => $status,
        ]);
    }

    /**
     * The same shoot row, re-read through a model whose save() the database
     * refuses. Created through the factory so every NOT NULL column is filled.
     */
    private function flakyShoot(): LockFlakyShoot
    {
        $shoot = $this->shootWithFloorPlan(['address' => '521 Brightfield Road']);

        return LockFlakyShoot::findOrFail($shoot->id);
    }

    private function ingestedFloorplan(Shoot $shoot, string $assetKey, string $filename): ShootFile
    {
        return ShootFile::query()->create([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => 'shoots/'.$shoot->id.'/floorplans/'.$filename,
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'media_type' => 'floorplan',
            'file_size' => 1024,
            'uploaded_by' => User::factory()->create()->id,
            'metadata' => [
                'source' => 'cubicasa',
                'cubicasa_asset_key' => $assetKey,
                'original_url' => 'https://s3.example.com/'.$filename,
            ],
        ]);
    }
}

/**
 * A Shoot whose save() is refused by the database for the first N attempts,
 * with the exception type and message SQLite actually produces.
 */
class LockFlakyShoot extends Shoot
{
    protected $table = 'shoots';

    public int $failUntilAttempt = 0;
    public int $saveCalls = 0;

    public function save(array $options = [])
    {
        $this->saveCalls++;

        if ($this->saveCalls <= $this->failUntilAttempt) {
            throw new QueryException(
                'sqlite',
                'update "shoots" set "cubicasa_status" = ? where "id" = ?',
                ['Ready', $this->id],
                new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')
            );
        }

        return parent::save($options);
    }
}

/**
 * Real service behaviour, except that one shoot can never be written. Models
 * the shoot whose applyShootData write kept losing the race in production.
 */
class FailsForOneShootCubiCasaService extends CubiCasaService
{
    public function __construct(private int $doomedShootId)
    {
        parent::__construct();
    }

    public function applyShootData(Shoot $shoot, array $parsed): Shoot
    {
        if ($shoot->id === $this->doomedShootId) {
            throw new QueryException(
                'sqlite',
                'update "shoots" set "cubicasa_status" = ? where "id" = ?',
                ['Ready', $shoot->id],
                new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')
            );
        }

        return parent::applyShootData($shoot, $parsed);
    }
}
