<?php

namespace Tests\MySql\Contracts;

use App\Database\MySqlConnection;
use App\Database\WriterTokenTimeout;
use App\Services\Scheduling\GoogleRoutesBudget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Run explicitly in the disposable writer-token fixture, without a loaded application schema. */
final class GoogleRoutesBudgetWriterTokenContract extends TestCase
{
    private Application $app;

    private PDO $admin;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', getenv('APP_ENV'));
        self::assertSame('isolated-v1', getenv('MYSQL_WRITER_TOKEN_FIXTURE'));
        self::assertSame('mysql', getenv('DB_CONNECTION'));
        self::assertSame('repro_mysql_test', getenv('DB_DATABASE'));
        self::assertSame('repro_test', getenv('DB_USERNAME'));
        self::assertSame('/fixture-socket/mysql.sock', getenv('DB_SOCKET'));
        $this->uuid = getenv('MYSQL_WRITER_TOKEN_UUID');
        $this->admin = new PDO(self::dsn(), 'repro_token_fixture', getenv('DB_PASSWORD'));
        $identity = $this->admin->query('SELECT @@server_uuid, VERSION(), @@skip_networking, CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        self::assertSame([$this->uuid, '8.4.13', 1, 'repro_token_fixture@localhost'], $identity);
        $tables = $this->admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'repro_mysql_test'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], array_diff($tables, ['_writer_token_probe', 'scheduling_route_usage']));
        $this->admin->exec('DROP TABLE IF EXISTS scheduling_route_usage');
        $schema = file_get_contents(dirname(__DIR__, 3).'/database/schema/mysql-schema.sql');
        self::assertSame(1, preg_match('/^CREATE TABLE `scheduling_route_usage`\s+\([^;]+;/m', $schema, $budgetTable));
        $this->admin->exec($budgetTable[0]);
        $this->admin->exec('DROP TABLE IF EXISTS repro_mysql_control.writer_token');
        $this->admin->exec('CREATE TABLE repro_mysql_control.writer_token (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id = 1),
            application_database VARBINARY(64) NOT NULL, contract_version INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->admin->exec("INSERT INTO repro_mysql_control.writer_token VALUES (1, 'repro_mysql_test', 1)");

        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        self::assertFalse($this->app['config']->get('database.connections.mysql.repro_route_budget_writer_token'));
        $this->configure(true, $this->uuid);
    }

    private static function dsn(): string
    {
        return 'mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test;charset=utf8mb4';
    }

    private function configure(bool $enabled, ?string $uuid): void
    {
        $this->app['config']->set([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.database' => 'repro_mysql_test',
            'database.connections.mysql.username' => 'repro_test',
            'database.connections.mysql.password' => getenv('DB_PASSWORD'),
            'database.connections.mysql.unix_socket' => '/fixture-socket/mysql.sock',
            'database.connections.mysql.repro_expected_server_uuid' => $uuid,
            'database.connections.mysql.repro_route_budget_writer_token' => $enabled,
            'services.google_routes.limit_elements' => 1,
        ]);
        $this->app['db']->purge('mysql');
    }

    private function db(): MySqlConnection
    {
        return $this->app['db']->connection();
    }

    private function reserve(): bool
    {
        return $this->app->make(GoogleRoutesBudget::class)->reserve();
    }

    private function reservedRows(): int
    {
        return (int) $this->admin->query('SELECT COUNT(*) FROM scheduling_route_usage')->fetchColumn();
    }

    private function holdToken(): void
    {
        $this->admin->beginTransaction();
        $this->admin->query('SELECT id FROM repro_mysql_control.writer_token WHERE id = 1 FOR UPDATE')->closeCursor();
    }

    protected function tearDown(): void
    {
        if (isset($this->admin) && $this->admin->inTransaction()) {
            $this->admin->rollBack();
        }
        if (isset($this->app)) {
            $this->app['db']->disconnect('mysql');
            $this->app->flush();
            HandleExceptions::flushState($this);
        }
        parent::tearDown();
    }

    public function test_query_event_observes_the_committed_row_and_the_original_session(): void
    {
        $pdo = $this->db()->getPdo();
        $pdo->exec("SET SESSION transaction_isolation = 'REPEATABLE-READ', SESSION innodb_lock_wait_timeout = 8, SESSION lock_wait_timeout = 60");
        $observations = [];
        $this->db()->listen(function (QueryExecuted $event) use (&$observations) {
            if ($event->sql === GoogleRoutesBudget::RESERVATION_SQL) {
                $connection = $event->connection->getPdo();
                $observations[] = [$connection->inTransaction(), $this->reservedRows(), $connection->query(
                    'SELECT @@SESSION.transaction_isolation, @@SESSION.innodb_lock_wait_timeout, @@SESSION.lock_wait_timeout'
                )->fetch(PDO::FETCH_NUM)];
            }
        });

        self::assertTrue($this->reserve());
        self::assertFalse($this->reserve());
        self::assertSame([[false, 1, ['REPEATABLE-READ', 8, 60]], [false, 1, ['REPEATABLE-READ', 8, 60]]], $observations);
        self::assertSame($pdo, $this->db()->getPdo());
    }

    public function test_token_timeout_leaves_no_rows_and_does_not_block_an_ordinary_read(): void
    {
        $this->holdToken();
        self::assertSame(0, $this->db()->table('scheduling_route_usage')->count());
        try {
            $this->reserve();
            self::fail('A reservation cannot proceed while its writer token is held.');
        } catch (WriterTokenTimeout) {
            self::assertNull($this->db()->getRawPdo());
        }
        self::assertSame(0, $this->reservedRows());
        $this->admin->rollBack();
        self::assertTrue($this->reserve());
    }

    public function test_disabled_flag_leaves_the_ordinary_reservation_path(): void
    {
        $this->configure(false, $this->uuid);
        $this->holdToken();
        self::assertTrue($this->reserve());
        $this->admin->rollBack();
        self::assertSame(1, $this->reservedRows());
    }

    public function test_missing_identity_and_raw_transactions_are_refused_without_writing(): void
    {
        $this->configure(true, null);
        try {
            $this->reserve();
            self::fail('An unpinned connection cannot opt in.');
        } catch (LogicException $error) {
            self::assertStringContainsString('approved MySQL UUID', $error->getMessage());
        }
        $this->configure(true, $this->uuid);
        $pdo = $this->db()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->reserve();
            self::fail('A raw transaction belongs to its caller.');
        } catch (LogicException $error) {
            self::assertStringContainsString('standalone', $error->getMessage());
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
        self::assertSame(0, $this->reservedRows());
    }

    public function test_a_guarded_sql_error_rolls_back_instead_of_reconnecting_and_replaying(): void
    {
        $connection = $this->db();
        $pdo = new PDO(self::dsn(), 'repro_test', getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STATEMENT_CLASS => [FailingReservationStatement::class, []],
        ]);
        $connection->setPdo($pdo);
        $connection->setReconnector(static function () {
            self::fail('A failed guarded reservation was replayed.');
        });
        try {
            $this->reserve();
            self::fail('The simulated statement failure must surface.');
        } catch (QueryException $error) {
            self::assertStringContainsString('MySQL server has gone away', $error->getMessage());
        }
        self::assertNull($connection->getRawPdo());
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $this->reservedRows());
    }

    public function test_nontransactional_table_drift_is_refused_before_the_insert(): void
    {
        $this->admin->exec('ALTER TABLE scheduling_route_usage ENGINE=MyISAM');
        try {
            $this->reserve();
            self::fail('A reservation cannot execute on a nontransactional table.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('requires the InnoDB budget table', $error->getMessage());
        }
        self::assertSame(0, $this->reservedRows());
    }

    public function test_query_listener_failure_does_not_replay_the_committed_reservation(): void
    {
        $calls = 0;
        $this->db()->listen(function (QueryExecuted $event) use (&$calls) {
            if ($event->sql === GoogleRoutesBudget::RESERVATION_SQL) {
                $calls++;
                self::assertSame(1, $this->reservedRows());

                throw new RuntimeException('Synthetic reservation listener failure.');
            }
        });
        try {
            $this->reserve();
            self::fail('The listener failure must surface.');
        } catch (RuntimeException $error) {
            self::assertSame('Synthetic reservation listener failure.', $error->getMessage());
        }
        self::assertSame(1, $calls);
        self::assertSame(1, $this->reservedRows());
    }

    public function test_lost_commit_acknowledgement_keeps_one_durable_row_without_reporting_success(): void
    {
        $connection = $this->db();
        $pdo = new class(self::dsn(), 'repro_test', getenv('DB_PASSWORD')) extends PDO
        {
            public function commit(): bool
            {
                parent::commit();

                throw new PDOException('Synthetic failure after a real COMMIT.');
            }
        };
        $connection->setPdo($pdo);
        $connection->setReconnector(static function () {
            self::fail('An ambiguous reservation was replayed.');
        });
        $events = [];
        $connection->listen(function (QueryExecuted $event) use (&$events) {
            if ($event->sql === GoogleRoutesBudget::RESERVATION_SQL) {
                $events[] = $event;
            }
        });
        try {
            $this->reserve();
            self::fail('An unacknowledged commit cannot report a successful reservation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('outcome of the MySQL route budget reservation is unknown', $error->getMessage());
            self::assertInstanceOf(PDOException::class, $error->getPrevious());
        }
        self::assertSame([], $events);
        self::assertNull($connection->getRawPdo());
        self::assertFalse($pdo->inTransaction());
        self::assertSame(1, $this->reservedRows());
    }

    public function test_two_processes_wait_for_the_same_token_and_only_spend_the_last_unit_once(): void
    {
        $script = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.repro_expected_server_uuid' => getenv('MYSQL_WRITER_TOKEN_UUID'),
            'database.connections.mysql.repro_route_budget_writer_token' => true,
            'services.google_routes.limit_elements' => 1]);
        $db = $app['db']->connection();
        if ($db->getDatabaseName() !== 'repro_mysql_test' || $db->getConfig('username') !== 'repro_test'
            || $db->getConfig('unix_socket') !== '/fixture-socket/mysql.sock') {
            throw new RuntimeException('Unsafe route budget fixture connection.');
        }
        touch($argv[2].'/ready-'.$argv[3]);
        $deadline = microtime(true) + 10;
        while (! is_file($argv[2].'/start') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($argv[2].'/start')) {
            throw new RuntimeException('The route budget worker was not released.');
        }
        $db->beforeExecuting(static function ($query) use ($argv) {
            if ($query === App\Services\Scheduling\GoogleRoutesBudget::RESERVATION_SQL) {
                touch($argv[2].'/attempting-'.$argv[3]);
            }
        });
        echo $app->make(App\Services\Scheduling\GoogleRoutesBudget::class)->reserve() ? 'reserved' : 'denied';
        PHP;
        $workers = [];
        $gate = sys_get_temp_dir().'/repro-route-budget-'.bin2hex(random_bytes(8));
        mkdir($gate, 0700);
        try {
            foreach ([1, 2] as $slot) {
                $worker = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 3), $gate, (string) $slot]);
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }
            $this->awaitWorkers($gate, 'ready', 8);
            $this->holdToken();
            touch($gate.'/start');
            $this->awaitWorkers($gate, 'attempting', 2);
            foreach ($workers as $worker) {
                self::assertTrue($worker->isRunning(), $worker->getErrorOutput());
            }
            self::assertSame(0, (int) $this->db()->table('scheduling_route_usage')->count());
            $this->admin->rollBack();

            $outcomes = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $outcomes[] = trim($worker->getOutput());
            }
            sort($outcomes);
            self::assertSame(['denied', 'reserved'], $outcomes);
            self::assertSame(1, $this->reservedRows());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(0, 9);
            }
            foreach (glob($gate.'/*') as $marker) {
                unlink($marker);
            }
            rmdir($gate);
        }
    }

    private function awaitWorkers(string $gate, string $marker, int $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while ((! is_file($gate.'/'.$marker.'-1') || ! is_file($gate.'/'.$marker.'-2')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($gate.'/'.$marker.'-1');
        self::assertFileExists($gate.'/'.$marker.'-2');
    }
}

final class FailingReservationStatement extends PDOStatement
{
    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if ($this->queryString === GoogleRoutesBudget::RESERVATION_SQL) {
            throw new PDOException('MySQL server has gone away (simulated after SQL execution).');
        }

        return $result;
    }
}
