<?php

namespace Tests\Feature;

use App\Services\Scheduling\GoogleRoutesBudget;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

class GoogleRoutesBudgetTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    public function test_reservations_enforce_exact_rolling_limit_and_do_not_refund_attempts(): void
    {
        $this->travelTo(now('UTC')->startOfSecond());
        config(['services.google_routes.limit_elements' => 100]);
        $budget = app(GoogleRoutesBudget::class);
        $this->assertTrue($budget->reserve(75));
        $this->assertSame(75, $budget->status()['alert_level']);
        $this->assertTrue($budget->reserve(15));
        $this->assertSame(90, $budget->status()['alert_level']);
        $this->assertFalse($budget->reserve(11));
        $this->assertTrue($budget->reserve(10));
        $this->assertFalse($budget->reserve());
        $status = $budget->status();
        $this->assertSame(100, $status['used_elements']);
        $this->assertSame(0, $status['remaining_elements']);
        $this->assertSame(100, $status['alert_level']);
        $this->assertSame(100, $status['budget_usd']);
        $this->assertDatabaseCount('scheduling_route_usage', 3);
        $this->travel(31)->days();
        $this->assertTrue($budget->reserve());
        $this->assertSame(1, $budget->status()['used_elements']);
    }

    public function test_limit_cannot_exceed_approved_budget_and_invalid_reservations_write_nothing(): void
    {
        config(['services.google_routes.limit_elements' => 20000]);
        $budget = app(GoogleRoutesBudget::class);
        $this->assertSame(10000, $budget->status()['limit_elements']);
        $this->assertFalse($budget->reserve(10001));
        $this->assertFalse($budget->reserve(0));
        $this->assertDatabaseCount('scheduling_route_usage', 0);
    }

    public function test_budget_cannot_be_reserved_in_a_booking_transaction(): void
    {
        $this->expectException(LogicException::class);
        DB::transaction(fn () => app(GoogleRoutesBudget::class)->reserve());
    }

    public function test_concurrent_workers_cannot_both_reserve_the_final_unit(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'routes-budget-');
        $gate = $database.'-gate';
        $connection = new PDO('sqlite:'.$database);
        $connection->exec('CREATE TABLE scheduling_route_usage (id INTEGER PRIMARY KEY, elements INTEGER NOT NULL, reserved_at TEXT NOT NULL)');
        $script = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[2], 'services.google_routes.limit_elements' => 1]);
        Illuminate\Support\Facades\DB::purge('sqlite');
        touch($argv[3].'.'.$argv[4]);
        $deadline = microtime(true) + 20;
        while (!file_exists($argv[3]) && microtime(true) < $deadline) { usleep(10000); }
        if (!file_exists($argv[3])) { exit(2); }
        echo $app->make(App\Services\Scheduling\GoogleRoutesBudget::class)->reserve() ? 'reserved' : 'denied';
        PHP;
        $workers = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, '-r', $script, base_path(), $database, $gate, (string) $worker], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(30);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 20;
            while ((! file_exists($gate.'.1') || ! file_exists($gate.'.2')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($gate.'.1');
            $this->assertFileExists($gate.'.2');
            touch($gate);
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = trim($worker->getOutput());
            }
            sort($results);
            $this->assertSame(['denied', 'reserved'], $results);
            $this->assertSame(1, (int) $connection->query('SELECT SUM(elements) FROM scheduling_route_usage')->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            $connection = null;
            foreach ([$gate.'.1', $gate.'.2', $gate, $database, $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
