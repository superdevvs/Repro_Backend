<?php

namespace Tests\Feature;

use App\Models\IguideLabProject;
use App\Models\IguideLabTourAnalysis;
use App\Models\IguideLabTourRun;
use App\Services\IguideLab\LabTourQueue;
use App\Services\IguideLab\LabTourRevisions;
use App\Services\IguideLab\LabTourView;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\Support\RecordingQueueFake;
use Tests\TestCase;

/** Push level, jobs-table pending check and monitor claims (spec 9.8), on a database without PHPUnit's enclosing transaction. */
class IguideLabTourQueueTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    private IguideLabProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->project = IguideLabProject::create(['name' => 'Tour queue fixture', 'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(10), 'max_runs' => 60]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_pending_job_check_finds_pending_and_reserved_rows_only_for_the_same_class_and_id(): void
    {
        [$a, $b] = [(string) Str::uuid(), (string) Str::uuid()];
        $check = fn (string $class, string $id, string $connection = 'iguide-lab-tour', string $queue = 'iguide-lab-tour') => LabTourQueue::pendingJobExists($connection, $queue, $class, $id);
        $this->assertFalse($check('TourQueueProbeJob', $a));

        Queue::connection('iguide-lab-tour')->push(new TourQueueProbeJob($a), '', 'iguide-lab-tour');
        Queue::connection('iguide-lab-tour')->push(new OtherTourProbeJob($b), '', 'iguide-lab-tour');
        Queue::connection('studio')->push(new TourQueueProbeJob($b), '', 'studio');
        $this->assertSame(3, DB::table('jobs')->count());

        $this->assertTrue($check('TourQueueProbeJob', $a));
        $this->assertFalse($check('TourQueueProbeJob', $b), 'same class on another queue, or same id with another class');
        $this->assertFalse($check('OtherTourProbeJob', $a));
        $this->assertTrue($check('OtherTourProbeJob', $b));
        $this->assertTrue($check('TourQueueProbeJob', $b, 'studio', 'studio'));
        $this->assertFalse($check('TourQueueProbeJob', (string) Str::uuid()));

        // A reserved row (popped by a worker, not yet deleted) still counts.
        $job = Queue::connection('iguide-lab-tour')->pop('iguide-lab-tour');
        $this->assertNotNull($job);
        $this->assertStringContainsString($a, $job->getRawBody());
        $this->assertNotNull(DB::table('jobs')->where('id', $job->getJobId())->value('reserved_at'));
        $this->assertTrue($check('TourQueueProbeJob', $a));
        $job->delete();
        $this->assertFalse($check('TourQueueProbeJob', $a));

        // A delayed successor is pending too.
        Queue::connection('iguide-lab-tour')->later(600, new TourQueueProbeJob($a), '', 'iguide-lab-tour');
        $this->assertTrue($check('TourQueueProbeJob', $a));
        // A connection without a jobs table never has a pending row.
        $this->assertFalse($check('TourQueueProbeJob', $a, 'sync', 'default'));

        // LabTourQueue::push uses the job's own connection and queue.
        DB::table('jobs')->delete();
        LabTourQueue::push((new TourQueueProbeJob($b))->onConnection('iguide-lab-tour')->onQueue('iguide-lab-tour'));
        $this->assertSame(['iguide-lab-tour'], DB::table('jobs')->pluck('queue')->all());
        $this->assertTrue($check('TourQueueProbeJob', $b));
    }

    public function test_push_runs_at_the_baseline_transaction_level_and_retries_a_locked_insert(): void
    {
        $fake = RecordingQueueFake::install($this);
        $this->assertSame(0, $fake->baselineLevel);
        $fake->failNextPush(new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
        LabTourQueue::push(new TourQueueProbeJob('locked-once'));
        $fake->assertPushed(TourQueueProbeJob::class, 1);
        $this->assertSame([0, 0], $fake->levels);

        // A failure that is not lock contention is not retried and reaches the caller.
        $fake->failNextPush(new \RuntimeException('queue table missing'));
        try {
            LabTourQueue::push(new TourQueueProbeJob('broken'));
            $this->fail('Expected the push failure to be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue table missing', $e->getMessage());
        }
        // Lock contention that never clears is rethrown after the bounded retries.
        foreach (range(1, 4) as $ignored) $fake->failNextPush(new \PDOException('database is locked'));
        try {
            LabTourQueue::push(new TourQueueProbeJob('always-locked'));
            $this->fail('Expected the push failure to be rethrown.');
        } catch (\PDOException $e) {
            $this->assertSame('database is locked', $e->getMessage());
        }
        $fake->assertPushed(TourQueueProbeJob::class, 1);

        // The fake catches a push made inside a transaction.
        $caught = null;
        try {
            DB::transaction(fn () => LabTourQueue::push(new TourQueueProbeJob('inside')));
        } catch (AssertionFailedError $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'A push inside a transaction must fail the recording fake.');
        $this->assertStringContainsString('baseline transaction level', $caught->getMessage());
        $this->assertSame(1, end($fake->levels));
        // This violation was provoked on purpose: take it, so the teardown check that install() registered passes.
        $violations = $fake->pullViolations();
        $this->assertCount(1, $violations);
        $this->assertStringStartsWith($violations[0], $caught->getMessage());
        $fake->assertAllPushesAtBaseline();
    }

    public function test_a_swallowed_push_assertion_still_fails_the_test_at_teardown(): void
    {
        $registered = count($this->beforeApplicationDestroyedCallbacks);
        $fake = RecordingQueueFake::install($this);
        $checks = array_slice($this->beforeApplicationDestroyedCallbacks, $registered);
        $this->assertCount(1, $checks, 'install() registers one teardown check on the test case.');
        $checks[0]();

        // A GET kick wraps its push in try/catch with a warning log (spec 9.8), so a kick run inside a transaction
        // swallows the fake's assertion and the job is simply not recorded.
        try {
            DB::transaction(fn () => LabTourQueue::push(new TourQueueProbeJob('kick-inside')));
        } catch (\Throwable $e) {
            Log::warning('Tour monitor kick failed.', ['error' => $e->getMessage()]);
        }
        $fake->assertNotPushed(TourQueueProbeJob::class);
        $this->assertSame([1], $fake->levels);

        // The teardown check still reports it, so the test fails even though nothing rethrew.
        foreach ([$checks[0], $fake->assertAllPushesAtBaseline(...)] as $check) {
            $caught = null;
            try {
                $check();
            } catch (AssertionFailedError $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'A swallowed push at the wrong level must still fail the test.');
            $this->assertStringContainsString('TourQueueProbeJob was pushed at transaction level 1', $caught->getMessage());
        }

        // A push at the baseline is not a violation; taking the provoked one lets this test's own teardown pass.
        LabTourQueue::push(new TourQueueProbeJob('baseline'));
        $fake->assertPushed(TourQueueProbeJob::class, 1);
        $this->assertCount(1, $fake->pullViolations());
        $this->assertSame([], $fake->pullViolations());
        $checks[0]();
    }

    public function test_claim_protocol(): void
    {
        $id = $this->analysis()->id;
        [$a, $b, $c] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $claim = fn (string $token, ?int $seq, bool $retry = false) => LabTourQueue::claimMonitor('iguide_lab_tour_analyses', $id, $token, $seq, $retry);
        $row = fn () => (array) DB::table('iguide_lab_tour_analyses')->where('id', $id)->first(['monitor_token', 'monitor_seq', 'monitor_heartbeat_at']);

        // A new chain takes a stopped monitor.
        $this->assertSame(1, $claim($a, null));
        $this->assertSame(['monitor_token' => $a, 'monitor_seq' => 1, 'monitor_heartbeat_at' => now()->toDateTimeString()], $row());
        // A second new chain cannot take a live one; a retry of the same first job can.
        $this->travel(60)->seconds();
        $this->assertNull($claim($b, null));
        $this->assertSame(1, $claim($a, null, true));
        // Successor needs seq - 1.
        $this->assertSame(2, $claim($a, 2));
        $this->assertSame(['monitor_token' => $a, 'monitor_seq' => 2, 'monitor_heartbeat_at' => now()->toDateTimeString()], $row());
        // A duplicate first attempt with the same (token, seq) exits; a retry of it proceeds.
        $this->assertNull($claim($a, 2));
        $this->assertSame(2, $claim($a, 2, true));
        $this->assertNull($claim($a, 4));
        $this->assertNull($claim($a, 4, true));
        $this->assertNull($claim($b, 3));
        $this->assertSame(3, $claim($a, 3));

        // Exactly heartbeat_stale_seconds old is still live; one second more is stale and a new chain takes over.
        $this->travel(300)->seconds();
        $this->assertNull($claim($b, null));
        $this->travel(1)->seconds();
        $this->assertSame(1, $claim($b, null));
        $this->assertSame(['monitor_token' => $b, 'monitor_seq' => 1, 'monitor_heartbeat_at' => now()->toDateTimeString()], $row());
        // The old chain fails the token test, first attempt or retry.
        $this->assertNull($claim($a, 4));
        $this->assertNull($claim($a, 4, true));
        $this->assertNull($claim($a, null));
        $this->assertSame(2, $claim($b, 2));

        // A chain that stopped (heartbeat and token cleared) is taken at once.
        IguideLabTourAnalysis::whereKey($id)->toBase()->update(['monitor_heartbeat_at' => null, 'monitor_token' => null]);
        $this->assertSame(1, $claim($c, null));
        $this->assertNull($claim($b, 3));

        // Runs use the same protocol; unknown rows and tables never proceed.
        $run = $this->tourRun($this->analysis(), 'twilight');
        $this->assertSame(1, LabTourQueue::claimMonitor('iguide_lab_tour_runs', $run->id, $a, null, false));
        $this->assertNull(LabTourQueue::claimMonitor('iguide_lab_tour_runs', $run->id, $b, null, false));
        $this->assertSame(2, LabTourQueue::claimMonitor('iguide_lab_tour_runs', $run->id, $a, 2, false));
        $this->assertNull(LabTourQueue::claimMonitor('iguide_lab_tour_runs', (string) Str::uuid(), $a, null, false));
        $this->expectException(\InvalidArgumentException::class);
        LabTourQueue::claimMonitor('iguide_lab_workflows', $id, $a, null, false);
    }

    public function test_bookkeeping_writes_do_not_touch_updated_at(): void
    {
        $analysis = $this->analysis();
        $run = $this->tourRun($analysis, 'golden-hour');
        $before = [$this->stamps('iguide_lab_tour_analyses', $analysis->id), $this->stamps('iguide_lab_tour_runs', $run->id)];
        $this->travel(10)->minutes();

        $token = (string) Str::uuid();
        $this->assertSame(1, LabTourQueue::claimMonitor('iguide_lab_tour_analyses', $analysis->id, $token, null, false));
        $this->assertSame(2, LabTourQueue::claimMonitor('iguide_lab_tour_analyses', $analysis->id, $token, 2, false));
        $this->assertSame(1, LabTourQueue::claimMonitor('iguide_lab_tour_runs', $run->id, $token, null, false));
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['monitor_idle' => true]);
        IguideLabTourRun::whereKey($run->id)->toBase()->update(['not_ready_ticks' => DB::raw('not_ready_ticks + 1')]);
        IguideLabTourAnalysis::whereKey($analysis->id)->toBase()->update(['monitor_heartbeat_at' => null, 'monitor_token' => null]);
        IguideLabTourRun::whereKey($run->id)->toBase()->update(['monitor_heartbeat_at' => null, 'monitor_token' => null]);

        $this->assertSame($before, [$this->stamps('iguide_lab_tour_analyses', $analysis->id), $this->stamps('iguide_lab_tour_runs', $run->id)]);
        $this->assertTrue($analysis->fresh()->monitor_idle);
        $this->assertSame(1, $analysis->fresh()->record_revision);
        $this->assertSame(1, $run->fresh()->not_ready_ticks);
        $this->assertSame(2, $analysis->fresh()->monitor_seq);
        // Control: an Eloquent update would have moved updatedAt.
        IguideLabTourAnalysis::whereKey($analysis->id)->update(['monitor_idle' => false]);
        $this->assertSame(now()->toDateTimeString(), DB::table('iguide_lab_tour_analyses')->where('id', $analysis->id)->value('updated_at'));
    }

    public function test_revision_bump_requires_the_write_transaction_and_view_load_opens_its_own_snapshot(): void
    {
        $analysis = $this->analysis();
        try {
            LabTourRevisions::bump($analysis->id);
            $this->fail('Expected a bump outside a transaction to be refused.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('transaction', $e->getMessage());
        }
        $this->assertSame(1, $analysis->fresh()->record_revision);
        $this->travel(5)->seconds();
        DB::transaction(fn () => LabTourRevisions::bump($analysis->id));
        $this->assertSame(2, $analysis->fresh()->record_revision);
        $this->assertSame(now()->toDateTimeString(), DB::table('iguide_lab_tour_analyses')->where('id', $analysis->id)->value('updated_at'));

        // Called at the baseline level, load() still reads one snapshot inside a transaction of its own.
        DB::enableQueryLog();
        $levels = [];
        DB::listen(function () use (&$levels) { $levels[] = DB::transactionLevel(); });
        $view = LabTourView::load($this->project->id, $analysis->id);
        $this->assertSame($analysis->id, $view->analysis->id);
        $this->assertNotEmpty($levels);
        $this->assertSame([1], array_values(array_unique($levels)));
        $this->assertSame(0, DB::transactionLevel());
    }

    private function analysis(): IguideLabTourAnalysis
    {
        $importId = (string) Str::uuid();
        DB::table('iguide_lab_imports')->insert(['id' => $importId, 'project_id' => $this->project->id, 'request_id' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'source_kind' => 'zip', 'private_input' => 'fixture', 'status' => 'completed',
            'sources' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        return IguideLabTourAnalysis::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'import_id' => $importId,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'priority_preset' => 'golden-hour', 'status' => 'analysing',
            'record_revision' => 1, 'selections' => ['golden-hour' => ['revision' => 0, 'changes' => []]]]);
    }

    private function tourRun(IguideLabTourAnalysis $analysis, string $preset): IguideLabTourRun
    {
        return IguideLabTourRun::create(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'analysis_id' => $analysis->id,
            'request_id' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64), 'preset' => $preset, 'status' => 'running',
            'selection_revision' => 1, 'selection_sha256' => str_repeat('d', 64), 'estimate' => ['roomsSelected' => 1], 'chunk_size' => 8]);
    }

    private function stamps(string $table, string $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first(['updated_at', 'created_at']);
    }
}

class TourQueueProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $monitoredId) {}

    public function handle(): void {}
}

class OtherTourProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $monitoredId) {}

    public function handle(): void {}
}
