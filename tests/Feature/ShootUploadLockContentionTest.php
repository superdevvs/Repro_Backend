<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\TestCase;

/**
 * Raw intake against a SQLite file that other writers keep committing to.
 *
 * Production runs SQLite in WAL mode with five queue workers. A photographer
 * uploading 109 CR3 frames to shoot 86 had 69 of them come back as "media
 * record could not be saved": the record INSERT lost a race with a worker
 * commit and SQLite refused it with "database is locked". These tests drive the
 * real storage service and inject that exact refusal on the record write.
 */
class ShootUploadLockContentionTest extends TestCase
{
    /** @var array<int, string> */
    private array $recordedLockRetries = [];

    /**
     * Deliberately not RefreshDatabase. That trait wraps each test in a
     * transaction, and inside an outer transaction the record write does not
     * retry (Laravel does not roll a savepoint back on a concurrency error, so a
     * retry could apply twice). A production request runs at the outermost
     * level, so these tests migrate a private in-memory database instead and run
     * there too. DatabaseMigrations is not used because its rollback trips on an
     * unrelated migration's down() under SQLite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->run();
        $this->app[Kernel::class]->setArtisan(null);
    }

    protected function tearDown(): void
    {
        $this->recordedLockRetries = [];
        parent::tearDown();
    }

    public function test_a_record_write_refused_by_a_locked_database_is_retried_and_the_file_is_accepted(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        Sanctum::actingAs($admin);

        // The first two INSERTs into shoot_files are refused the way SQLite does
        // it, then the database is "free" again.
        $this->refuseShootFileInsertsWithLockedDatabase(times: 2);

        $response = $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->image('SNAP8541.CR3.jpg', 800, 600)],
            'upload_type' => 'raw',
            'idempotency_key' => 'lock-retry-accepts',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('error_count', 0)
            ->assertJsonPath('uploaded_files.0.filename', 'SNAP8541.CR3.jpg');
        $this->assertNotEmpty($response->json('correlation_id'), 'every upload response carries a correlation id for log lookup');

        $this->assertCount(2, $this->recordedLockRetries, 'both refusals were hit before the write landed');
        $this->assertSame(1, ShootFile::query()->where('shoot_id', $shoot->id)->count(), 'a retried transaction must not leave duplicate rows');

        $file = ShootFile::query()->where('shoot_id', $shoot->id)->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_contention_that_never_clears_is_reported_as_retryable_and_leaves_nothing_behind(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        Sanctum::actingAs($admin);

        $this->refuseShootFileInsertsWithLockedDatabase(times: PHP_INT_MAX);

        $response = $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->image('SNAP8541.CR3.jpg', 800, 600)],
            'upload_type' => 'raw',
            'idempotency_key' => 'lock-retry-gives-up',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success_count', 0)
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('errors.0.file_name', 'SNAP8541.CR3.jpg')
            ->assertJsonPath('errors.0.error_type', 'storage_failure')
            ->assertJsonPath('errors.0.retryable', true);
        $this->assertStringContainsString('database was busy', $response->json('errors.0.message'));

        $this->assertSame(
            ShootMediaStorageService::RECORD_WRITE_ATTEMPTS,
            count($this->recordedLockRetries),
            'the write is attempted a bounded number of times, not forever'
        );
        $this->assertSame(0, ShootFile::query()->where('shoot_id', $shoot->id)->count());

        // The bytes were moved into place before the record write; a failed
        // record must take them with it or the disk fills with orphans.
        $this->assertSame([], Storage::disk('public')->allFiles(), 'staged bytes are discarded when the record cannot be saved');
    }

    public function test_a_constraint_violation_is_not_mistaken_for_contention_and_fails_on_the_first_attempt(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        Sanctum::actingAs($admin);

        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (! str_starts_with(strtolower(trim($query->sql)), 'insert into "shoot_files"')) {
                return;
            }

            $attempts++;
            throw new QueryException(
                'sqlite',
                $query->sql,
                $query->bindings,
                new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: shoot_files.path')
            );
        });

        $response = $this->post('/api/shoots/'.$shoot->id.'/upload', [
            'files' => [UploadedFile::fake()->image('SNAP8541.CR3.jpg', 800, 600)],
            'upload_type' => 'raw',
            'idempotency_key' => 'constraint-not-retried',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('error_count', 1)
            ->assertJsonPath('errors.0.error_type', 'storage_failure');
        $this->assertSame(1, $attempts, 'a genuine failure must surface immediately, never be retried');
        $this->assertSame(0, ShootFile::query()->where('shoot_id', $shoot->id)->count());
    }

    /**
     * Make the next $times INSERTs into shoot_files fail exactly as PDO reports
     * SQLITE_BUSY. The statement has already run when the listener fires, so the
     * exception rolls the surrounding transaction back and the row disappears
     * with it — the same net effect as the real refusal.
     */
    private function refuseShootFileInsertsWithLockedDatabase(int $times): void
    {
        $remaining = $times;

        DB::listen(function (QueryExecuted $query) use (&$remaining): void {
            if ($remaining <= 0 || ! str_starts_with(strtolower(trim($query->sql)), 'insert into "shoot_files"')) {
                return;
            }

            $remaining--;
            $this->recordedLockRetries[] = $query->sql;

            throw new QueryException(
                'sqlite',
                $query->sql,
                $query->bindings,
                new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')
            );
        });
    }
}
