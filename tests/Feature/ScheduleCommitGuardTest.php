<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Scheduling\ScheduleCommitGuard;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\TravelLocationResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ScheduleCommitGuardTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    protected function setUp(): void
    {
        parent::setUp();
        config(['availability.hybrid_travel_enabled' => true, 'availability.scheduling_lock_store' => 'array']);
    }

    private function engine(array $result): \Mockery\MockInterface
    {
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('assertResult')->andReturnNull();
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        return $engine;
    }

    private function available(string $version = 'first'): array
    {
        return ['enabled' => true, 'available' => true, 'status' => 'available', 'schedule_version' => $version,
            'photographer_ids' => [9, 3], 'visits' => [], 'location' => [], 'transitions' => [], 'reason_codes' => []];
    }

    public function test_network_preparation_is_outside_transaction_and_write_holds_sorted_shared_locks(): void
    {
        $result = $this->available();
        $engine = $this->engine($result);
        $engine->shouldReceive('evaluate')->once()->andReturnUsing(function () use ($result) {
            $this->assertSame(0, DB::transactionLevel());
            return $result;
        });
        $engine->shouldReceive('scheduleFingerprint')->twice()->with([3, 9])->andReturn('first');
        $guard = app(ScheduleCommitGuard::class);
        $prepared = $guard->prepare([]);
        $value = $guard->commit($prepared, function () {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertFalse(Cache::store('array')->lock('schedule:photographer:3')->get());
            $this->assertFalse(Cache::store('array')->lock('schedule:photographer:9')->get());
            return 'saved';
        });
        $this->assertSame('saved', $value);
        $lock = Cache::store('array')->lock('schedule:photographer:3');
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_neighbor_change_releases_locks_and_recomputes_once_before_writing(): void
    {
        $first = $this->available();
        $second = $this->available('second');
        $engine = $this->engine($first);
        $calls = 0;
        $engine->shouldReceive('evaluate')->twice()->andReturnUsing(function () use (&$calls, $first, $second) {
            $this->assertSame(0, DB::transactionLevel());
            $lock = Cache::store('array')->lock('schedule:photographer:3');
            $this->assertTrue($lock->get());
            $lock->release();
            return ++$calls === 1 ? $first : $second;
        });
        $engine->shouldReceive('scheduleFingerprint')->times(3)->andReturn('second');
        $guard = app(ScheduleCommitGuard::class);
        $writes = 0;
        $guard->commit($guard->prepare([]), function () use (&$writes) { $writes++; });
        $this->assertSame(1, $writes);
    }

    public function test_repeated_concurrent_change_returns_conflict_without_writing(): void
    {
        $result = $this->available();
        $engine = $this->engine($result);
        $engine->shouldReceive('evaluate')->twice()->andReturn($result);
        $engine->shouldReceive('scheduleFingerprint')->twice()->andReturn('changed');
        $guard = app(ScheduleCommitGuard::class);
        $this->expectException(ConflictHttpException::class);
        $guard->commit($guard->prepare([]), fn () => $this->fail('Stale plans must not write.'));
    }

    public function test_changed_target_is_rejected_instead_of_replaying_stale_action_payload(): void
    {
        $shoot = Shoot::factory()->create(['photographer_id' => null]);
        $result = $this->available();
        $engine = $this->engine($result);
        $engine->shouldReceive('evaluate')->once()->andReturn($result);
        $engine->shouldNotReceive('scheduleFingerprint');
        $guard = app(ScheduleCommitGuard::class);
        $prepared = $guard->prepare([], $shoot);
        $shoot->forceFill(['address' => 'Changed concurrently'])->saveQuietly();
        $this->expectException(ConflictHttpException::class);
        $guard->commit($prepared, fn () => $this->fail('The edited target is stale.'));
    }

    public function test_persisted_location_and_successful_exception_audit_exclude_provider_eta(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['photographer_id' => null, 'property_details' => ['beds' => 4]]);
        $result = array_merge($this->available(), ['status' => 'conflict', 'available' => false,
            'policy_version' => 'test', 'reason_codes' => ['insufficient_travel_time'],
            'location' => ['verified' => true],
            'transitions' => [['id' => 'edge', 'direction' => 'incoming', 'photographer_id' => 3,
                'source' => 'google_routes', 'drive_minutes' => 27, 'required_minutes' => 35]]]);
        $engine = $this->engine($result);
        $engine->shouldReceive('evaluate')->once()->andReturn($result);
        $engine->shouldReceive('scheduleFingerprint')->twice()->andReturn('first');
        $resolver = Mockery::mock(TravelLocationResolver::class);
        $resolver->shouldReceive('persistedMetadata')->once()->with(['verified' => true])->andReturn(['signature' => 'trusted']);
        $this->app->instance(TravelLocationResolver::class, $resolver);
        $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('notice')->once()->with('schedule_travel_override', Mockery::on(function ($context) use ($actor, $shoot) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(1, UserActivityLog::where('event_type', 'schedule.travel_override')->count());
            $this->assertSame(['actor_id' => $actor->id, 'shoot_ids' => [$shoot->id], 'transition_ids' => ['edge'],
                'reason_codes' => ['insufficient_travel_time'], 'policy_version' => 'test'], $context);
            return true;
        }));
        Log::partialMock()->shouldReceive('channel')->once()->with('scheduling')->andReturn($logger);
        $guard = app(ScheduleCommitGuard::class);
        $prepared = $guard->prepare(['travel_override' => true, 'travel_override_reason' => 'Approved travel exception',
            'property_details' => ['schedule_location' => ['signature' => 'forged']]], $shoot, $actor);
        $guard->commit($prepared, fn () => $shoot);
        $this->assertSame(['beds' => 4, 'schedule_location' => ['signature' => 'trusted']], $shoot->fresh()->property_details);
        $audit = UserActivityLog::where('event_type', 'schedule.travel_override')->sole();
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertSame([$shoot->id], $audit->metadata['shoot_ids']);
        $this->assertSame([['id' => 'edge', 'direction' => 'incoming', 'photographer_id' => 3, 'source' => 'google_routes']], $audit->metadata['transitions']);
    }

    public function test_network_preparation_rejects_an_ambient_transaction(): void
    {
        $this->expectException(\LogicException::class);
        DB::transaction(fn () => app(ScheduleCommitGuard::class)->prepare([]));
    }

    public function test_disabled_guard_keeps_legacy_transaction_contract(): void
    {
        config(['availability.hybrid_travel_enabled' => false]);
        $guard = app(ScheduleCommitGuard::class);
        $this->assertSame('legacy', DB::transaction(fn () => $guard->commit($guard->prepare([]), fn () => 'legacy')));
    }

    public function test_database_lock_store_excludes_another_owner_and_guard_returns_409_on_contention(): void
    {
        config(['availability.scheduling_lock_store' => 'scheduling', 'availability.scheduling_lock_wait_seconds' => 0]);
        $result = $this->available();
        $engine = $this->engine($result);
        $engine->shouldReceive('evaluate')->once()->andReturn($result);
        $guard = app(ScheduleCommitGuard::class);
        $prepared = $guard->prepare([]);
        $owner = Cache::store('scheduling')->lock('schedule:photographer:3', 30, 'other-process');
        $this->assertTrue($owner->get());
        $contender = Cache::store('scheduling')->lock('schedule:photographer:3', 30, 'this-process');
        $this->assertFalse($contender->get());
        $this->assertFalse($contender->release());
        $this->assertSame('other-process', DB::table('cache_locks')->sole()->owner);
        try {
            $guard->commit($prepared, fn () => $this->fail('The other process owns this photographer lock.'));
            $this->fail('Expected a schedule conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        } finally {
            $owner->release();
        }
        $this->assertTrue($contender->get());
        $contender->release();
    }
}
