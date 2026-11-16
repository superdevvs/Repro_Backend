<?php

namespace Tests\Support;

use App\Models\IguideLabProject;
use App\Models\IguideLabWorkflow;
use App\Services\IguideLab\LabWorkflows;
use Closure;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use ReflectionMethod;

/**
 * LabWorkflows test double (spec 13.1, WP4). start(), cancel() and stop() assert that they run at the test's baseline
 * transaction level (never inside a lab write transaction, spec conventions), record every call, then call the real
 * method, which is never edited (spec 2.3).
 *
 * The tour layer catches every Throwable from start() (spec 9.4 step 8), so a thrown assertion could be swallowed.
 * Every off-baseline call is therefore also recorded, and install() registers a teardown check that fails the test.
 *
 * Hooks:
 * - $beforeStart runs before the real start() (for example a POST cancel that commits before the workflow exists);
 * - $override replaces the real start(); it receives the project, the input and a closure that calls the real start(),
 *   so a test can throw before, or after, the workflow was created;
 * - $beforeReturn runs after the real start() returned the workflow and before it reaches the caller (a POST cancel
 *   committed while start() is still in flight).
 */
class TransactionAssertingLabWorkflows extends LabWorkflows
{
    public int $baselineLevel = 0;

    /** @var list<array{project:string,input:array,level:int}> */
    public array $startCalls = [];

    /** @var list<string> workflow ids */
    public array $cancelCalls = [];

    /** @var list<array{workflow:string,code:string}> */
    public array $stopCalls = [];

    public ?Closure $beforeStart = null;
    public ?Closure $override = null;
    public ?Closure $beforeReturn = null;

    /** @var list<string> */
    private array $violations = [];

    public static function install(TestCase $test): self
    {
        $double = new self;
        $double->baselineLevel = DB::transactionLevel();
        app()->instance(LabWorkflows::class, $double);
        (new ReflectionMethod($test, 'beforeApplicationDestroyed'))->invoke($test, fn () => $double->assertAllCallsAtBaseline());

        return $double;
    }

    public function start(IguideLabProject $project, array $input): IguideLabWorkflow
    {
        $this->startCalls[] = ['project' => $project->id, 'input' => $input, 'level' => DB::transactionLevel()];
        $this->assertBaseline('start');
        if ($this->beforeStart) ($this->beforeStart)($project, $input);
        $real = fn () => parent::start($project, $input);
        $workflow = $this->override ? ($this->override)($project, $input, $real) : $real();
        if ($this->beforeReturn) ($this->beforeReturn)($workflow);

        return $workflow;
    }

    public function cancel(IguideLabWorkflow $workflow): IguideLabWorkflow
    {
        $this->cancelCalls[] = $workflow->id;
        $this->assertBaseline('cancel');

        return parent::cancel($workflow);
    }

    public function stop(IguideLabWorkflow $workflow, string $code, string $message): void
    {
        // The real start() calls stop() itself when its own dispatch fails; that call is at the same level.
        $this->stopCalls[] = ['workflow' => $workflow->id, 'code' => $code];
        $this->assertBaseline('stop');
        parent::stop($workflow, $code, $message);
    }

    public function assertAllCallsAtBaseline(): void
    {
        if ($this->violations !== []) Assert::fail(implode("\n", $this->violations));
    }

    private function assertBaseline(string $method): void
    {
        $level = DB::transactionLevel();
        if ($level === $this->baselineLevel) return;
        $message = sprintf('LabWorkflows::%s() ran at transaction level %d, not at the baseline level %d (spec conventions).', $method, $level, $this->baselineLevel);
        $this->violations[] = $message;
        Assert::fail($message);
    }
}
