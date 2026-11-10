<?php

namespace Tests\Feature;

use App\Jobs\ProcessUpdatedShootSideEffectsJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\ScheduleCommitGuard;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\TravelLocationResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ShootRescheduleContentionTest extends TestCase
{
    public function test_reschedule_reserves_writer_before_schedule_reads_and_commits_once(): void
    {
        $this->withDatabase(function (Shoot $shoot, \PDO $writer) {
            $blocked = 0;
            $committed = 0;
            DB::listen(function (QueryExecuted $query) use ($writer, &$blocked, &$committed) {
                if (DB::transactionLevel() !== 1 || ! str_starts_with(strtolower($query->sql), 'select')
                    || ! str_contains($query->sql, '"shoots"')) {
                    return;
                }
                try {
                    // Another queue connection commits between schedule reads and writes.
                    $writer->exec('UPDATE reschedule_contention_marker SET value = value + 1');
                    $committed++;
                } catch (\PDOException $exception) {
                    $this->assertSame(5, (int) $exception->errorInfo[1]);
                    $blocked++;
                }
            });
            $this->patchJson('/api/shoots/'.$shoot->id, $this->payload())->assertOk();
            $this->assertGreaterThan(0, $blocked);
            $this->assertSame(0, $committed, 'Concurrent writes must not invalidate the scheduling snapshot.');
            $this->assertMovedOnce($shoot);
            $writer->exec('UPDATE reschedule_contention_marker SET value = 9');
            $this->assertSame(9, (int) DB::table('reschedule_contention_marker')->value('value'));
        });
    }

    public function test_nested_lock_failure_restarts_the_outer_schedule_transaction(): void
    {
        $this->withDatabase(function (Shoot $shoot) {
            $scheduleReads = 0;
            $writeSnapshots = [];
            DB::listen(function (QueryExecuted $query) use (&$scheduleReads, &$writeSnapshots) {
                if (DB::transactionLevel() === 1 && str_starts_with(strtolower($query->sql), 'select')
                    && str_contains($query->sql, '"shoots"')) {
                    $scheduleReads++;
                }
                if (! str_starts_with($query->sql, 'update "shoots" set "units_revision" = units_revision')) {
                    return;
                }
                $writeSnapshots[] = $scheduleReads;
                if (count($writeSnapshots) === 1) {
                    throw new QueryException('sqlite', $query->sql, [], new \PDOException('database is locked'));
                }
            });
            $this->patchJson('/api/shoots/'.$shoot->id, $this->payload())->assertOk();
            $this->assertCount(2, $writeSnapshots);
            $this->assertGreaterThan($writeSnapshots[0], $writeSnapshots[1], 'Retry must recheck the schedule in a fresh outer transaction.');
            $this->assertMovedOnce($shoot);
        });
    }

    public function test_persistent_writer_contention_is_retryable_without_partial_reschedule(): void
    {
        $this->withDatabase(function (Shoot $shoot, \PDO $writer, ScheduleFeasibilityService $engine) {
            $startWriter = true;
            $engine->shouldReceive('scheduleFingerprint')->andReturnUsing(function () use ($writer, &$startWriter) {
                if ($startWriter) {
                    $startWriter = false;
                    $writer->beginTransaction();
                    $writer->exec('UPDATE reschedule_contention_marker SET value = 1');
                }

                return 'stable';
            });
            try {
                $this->patchJson('/api/shoots/'.$shoot->id, $this->payload())
                    ->assertConflict()->assertJsonPath('code', 'schedule_busy')->assertJsonPath('retryable', true);
                $this->assertSame('2026-11-24 20:00:00', $shoot->fresh()->getRawOriginal('scheduled_at'));
                $this->assertSame('2026-11-24 20:00:00', $shoot->serviceItems()->sole()->getRawOriginal('scheduled_at'));
                Queue::assertNotPushed(ProcessUpdatedShootSideEffectsJob::class);
                $this->assertSame(0, DB::transactionLevel());
            } finally {
                if ($writer->inTransaction()) {
                    $writer->rollBack();
                }
            }
            $this->patchJson('/api/shoots/'.$shoot->id, $this->payload())->assertOk();
            $this->assertMovedOnce($shoot);
        });
    }

    public function test_late_retry_keeps_staged_listing_fields_and_dispatches_only_once(): void
    {
        $this->withDatabase(function (Shoot $shoot) {
            $calls = 0;
            $resolver = Mockery::mock(TravelLocationResolver::class);
            $resolver->shouldReceive('persistedMetadata')->twice()->andReturnUsing(function () use (&$calls) {
                if (++$calls === 1) {
                    throw new \PDOException('database is locked');
                }

                return [];
            });
            $this->app->instance(TravelLocationResolver::class, $resolver);
            $this->patchJson('/api/shoots/'.$shoot->id, $this->payload() + ['is_listing_hidden' => true])->assertOk();
            $this->assertTrue($shoot->fresh()->is_listing_hidden);
            $this->assertMovedOnce($shoot);
        }, ['location' => ['verified' => true]]);
    }

    public function test_database_lock_cleanup_does_not_mask_a_successful_commit(): void
    {
        $this->withDatabase(function (Shoot $shoot, \PDO $writer) {
            config(['availability.scheduling_lock_store' => 'scheduling']);
            $guard = app(ScheduleCommitGuard::class);
            try {
                $value = $guard->commit($guard->prepare([], $shoot), function () use ($writer) {
                    DB::table('reschedule_contention_marker')->update(['value' => 2]);
                    DB::afterCommit(function () use ($writer) {
                        $writer->beginTransaction();
                        $writer->exec('UPDATE reschedule_contention_marker SET value = 3');
                    });

                    return 'committed';
                });
                $this->assertSame('committed', $value);
                $this->assertSame(0, DB::transactionLevel());
            } finally {
                if ($writer->inTransaction()) {
                    $writer->rollBack();
                }
            }
            $this->assertSame(2, (int) DB::table('reschedule_contention_marker')->value('value'));
            $this->assertDatabaseCount('cache_locks', 1); // Original TTL will release the deferred cleanup.
        });
    }

    private function payload(): array
    {
        return ['scheduled_date' => '2026-11-23', 'time' => '11:00:00', 'notify_client' => false, 'notify_photographer' => false];
    }

    private function assertMovedOnce(Shoot $shoot): void
    {
        $shoot->refresh();
        $this->assertSame('2026-11-23 16:00:00', $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-11-23', $shoot->scheduled_date->toDateString());
        $this->assertSame('11:00:00', $shoot->time);
        $this->assertSame('2026-11-23 16:00:00', $shoot->serviceItems()->sole()->getRawOriginal('scheduled_at'));
        $this->assertSame(1, DB::table('shoot_activity_logs')->where('shoot_id', $shoot->id)->where('action', 'shoot_updated')->count());
        Queue::assertPushed(ProcessUpdatedShootSideEffectsJob::class, 1);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertSame(0, DB::transactionLevel());
    }

    private function withDatabase(callable $test, array $resultOverrides = []): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reschedule-contention-');
        DB::purge('sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $path,
            'app.timezone' => 'UTC', 'cache.default' => 'array', 'availability.hybrid_travel_enabled' => true,
            'availability.scheduling_lock_store' => 'array', 'media.archive_prewarm' => false]);
        Carbon::setTestNow('2026-11-10 16:00:00');
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $writer = null;
        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=25');
            DB::statement('CREATE TABLE reschedule_contention_marker (value INTEGER)');
            DB::table('reschedule_contention_marker')->insert(['value' => 0]);
            $admin = User::factory()->admin()->create();
            $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
            $service = Service::factory()->create(['price' => 100, 'photographer_required' => true]);
            $photographer->setServiceCapabilities([$service->id]);
            $photographer->save();
            // Same timing as the reported booking; no customer data or live writes.
            $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id,
                'scheduled_at' => '2026-11-24 20:00:00', 'scheduled_date' => '2026-11-24', 'time' => '15:00:00',
                'timezone' => 'America/New_York', 'status' => 'scheduled', 'workflow_status' => 'scheduled', 'is_listing_hidden' => false]);
            $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1,
                'photographer_id' => $photographer->id, 'scheduled_at' => '2026-11-24 20:00:00',
                'duration_minutes' => 90, 'workflow_status' => 'scheduled', 'is_deliverable' => true]);
            $engine = Mockery::mock(ScheduleFeasibilityService::class)->makePartial();
            $engine->shouldReceive('evaluate')->andReturn(array_replace(['enabled' => true, 'available' => true,
                'status' => 'available', 'schedule_version' => 'stable', 'photographer_ids' => [$photographer->id],
                'visits' => [], 'location' => [], 'transitions' => [], 'reason_codes' => []], $resultOverrides));
            $engine->shouldReceive('assertResult')->andReturnNull();
            $engine->shouldReceive('scheduleFingerprint')->andReturn('stable')->byDefault();
            $this->app->instance(ScheduleFeasibilityService::class, $engine);
            $writer = new \PDO('sqlite:'.$path);
            $writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $writer->exec('PRAGMA busy_timeout=0');
            $this->actingAs($admin);
            $test($shoot, $writer, $engine);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('sqlite');
            RefreshDatabaseState::$migrated = false;
            $writer = null;
            Carbon::setTestNow();
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
