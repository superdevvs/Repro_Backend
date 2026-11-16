<?php

namespace Tests\Support;

use App\Services\IguideLab\LabWorkflowPolicy;
use Closure;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use ReflectionMethod;

/**
 * LabWorkflowPolicy spy (spec 13.1, WP4/WP5). snapshot() hashes files and capabilities() probes runtimes, so neither may
 * run under SQLite's single writer lock (spec 8, S6). The spy records the transaction level of every call, asserts it is
 * the test's baseline, and then answers with the real (never edited) implementation.
 *
 * Off-baseline calls are also recorded and fail the test at teardown, because a caller may catch the thrown assertion.
 * $snapshotFilter can change the real snapshot (for example drop lightingRepair) without editing the policy class.
 */
class SpyLabWorkflowPolicy extends LabWorkflowPolicy
{
    public int $baselineLevel = 0;

    /** @var list<array{method:string,level:int}> */
    public array $calls = [];

    public ?Closure $snapshotFilter = null;

    /** @var list<string> */
    private array $violations = [];

    public static function install(TestCase $test): self
    {
        $spy = new self;
        $spy->baselineLevel = DB::transactionLevel();
        app()->instance(LabWorkflowPolicy::class, $spy);
        (new ReflectionMethod($test, 'beforeApplicationDestroyed'))->invoke($test, fn () => $spy->assertAllCallsAtBaseline());

        return $spy;
    }

    public function snapshot(): array
    {
        $this->record('snapshot');
        $snapshot = parent::snapshot();

        return $this->snapshotFilter ? ($this->snapshotFilter)($snapshot) : $snapshot;
    }

    public function capabilities(): array
    {
        $this->record('capabilities');

        return parent::capabilities();
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }

    public function assertAllCallsAtBaseline(): void
    {
        if ($this->violations !== []) Assert::fail(implode("\n", $this->violations));
    }

    private function record(string $method): void
    {
        $level = DB::transactionLevel();
        $this->calls[] = ['method' => $method, 'level' => $level];
        if ($level === $this->baselineLevel) return;
        $message = sprintf('LabWorkflowPolicy::%s() ran at transaction level %d, not at the baseline level %d (spec 8.1).', $method, $level, $this->baselineLevel);
        $this->violations[] = $message;
        Assert::fail($message);
    }
}
