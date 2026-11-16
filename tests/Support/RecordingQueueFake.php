<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Queue::fake() that also proves where each push happens (spec 13.1).
 *
 * The plain QueueFake records a push immediately and ignores after_commit, so it cannot tell a dispatch made
 * inside a lab write transaction from one made after it. This fake records DB::transactionLevel() at every
 * push and fails the test unless it equals the level captured by install(): 1 under RefreshDatabase, 0 under
 * FreshDatabaseOutsideTransaction. All tour tests use it instead of Queue::fake().
 */
class RecordingQueueFake extends QueueFake
{
    /** Transaction level when the fake was installed (the test's baseline). */
    public int $baselineLevel = 0;

    /** @var list<int> Transaction level at every push attempt, failed attempts included. */
    public array $levels = [];

    /** @var list<Throwable> Thrown by the next push attempts, in order, before anything is recorded. */
    private array $failures = [];

    public static function install(array $jobsToFake = []): self
    {
        $real = Queue::isFake() ? Queue::getFacadeRoot()->queue : Queue::getFacadeRoot();
        $fake = new self(app(), $jobsToFake, $real);
        $fake->baselineLevel = DB::transactionLevel();
        Queue::swap($fake);

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
        Assert::assertSame($this->baselineLevel, $level, sprintf(
            'Job %s was pushed at transaction level %d, not at the baseline transaction level %d (spec conventions: Push).',
            is_object($job) ? get_class($job) : (string) $job, $level, $this->baselineLevel));
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return parent::push($job, $data, $queue);
    }
}
