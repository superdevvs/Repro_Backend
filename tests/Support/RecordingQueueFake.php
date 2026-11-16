<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Assert;
use ReflectionMethod;
use Throwable;

/**
 * Queue::fake() that also proves where each push happens (spec 13.1).
 *
 * The plain QueueFake records a push immediately and ignores after_commit, so it cannot tell a dispatch made
 * inside a lab write transaction from one made after it. This fake records DB::transactionLevel() at every
 * push and fails the test unless it equals the level captured by install(): 1 under RefreshDatabase, 0 under
 * FreshDatabaseOutsideTransaction. All tour tests use it instead of Queue::fake().
 *
 * push() throws the failure and also records it. A GET kick catches every Throwable from its push (spec 9.8),
 * so a caller could swallow the thrown assertion. install() therefore registers a teardown check on the test
 * case that fails the test for any recorded violation.
 */
class RecordingQueueFake extends QueueFake
{
    /** Transaction level when the fake was installed (the test's baseline). */
    public int $baselineLevel = 0;

    /** @var list<int> Transaction level at every push attempt, failed attempts included. */
    public array $levels = [];

    /** @var list<string> One message per push made away from the baseline level, whether or not its assertion was caught. */
    private array $violations = [];

    /** @var list<Throwable> Thrown by the next push attempts, in order, before anything is recorded. */
    private array $failures = [];

    /** Swap the queue for this fake. At the test's teardown, fail the test if any push left the baseline level. */
    public static function install(TestCase $test, array $jobsToFake = []): self
    {
        $real = Queue::isFake() ? Queue::getFacadeRoot()->queue : Queue::getFacadeRoot();
        $fake = new self(app(), $jobsToFake, $real);
        $fake->baselineLevel = DB::transactionLevel();
        Queue::swap($fake);
        // Laravel runs these callbacks in tearDown and rethrows the first failure after its cleanup. The method is
        // protected on the test case, and app()->terminating() never runs in tests (tearDown flushes the app).
        (new ReflectionMethod($test, 'beforeApplicationDestroyed'))->invoke($test, fn () => $fake->assertAllPushesAtBaseline());

        return $fake;
    }

    /** Make the next push attempt throw, for example a PDOException "database is locked" for one insert. */
    public function failNextPush(Throwable $failure): static
    {
        $this->failures[] = $failure;

        return $this;
    }

    public function push($job, $data = '', $queue = null)
    {
        $this->levels[] = $level = DB::transactionLevel();
        $message = sprintf('Job %s was pushed at transaction level %d, not at the baseline transaction level %d (spec conventions: Push).',
            is_object($job) ? get_class($job) : (string) $job, $level, $this->baselineLevel);
        if ($level !== $this->baselineLevel) {
            $this->violations[] = $message;
        }
        Assert::assertSame($this->baselineLevel, $level, $message);
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return parent::push($job, $data, $queue);
    }

    /** Fail if any push so far left the baseline level, even when the caller caught that push's own assertion. */
    public function assertAllPushesAtBaseline(): void
    {
        if ($this->violations !== []) {
            Assert::fail(implode("\n", $this->violations));
        }
    }

    /**
     * Return and forget the recorded violations, for a test that provokes one on purpose.
     *
     * @return list<string>
     */
    public function pullViolations(): array
    {
        [$violations, $this->violations] = [$this->violations, []];

        return $violations;
    }
}
