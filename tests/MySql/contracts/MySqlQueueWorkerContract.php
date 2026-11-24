<?php

namespace Tests\MySql\Contracts;

use App\Jobs\DispatchScheduledMessages;
use App\Queue\MySqlWorkerDatabaseQueue;
use App\Queue\SqliteDatabaseQueue;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Queue\DatabaseQueue;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Run explicitly against the disposable socket-only queue worker fixture. */
final class MySqlQueueWorkerContract extends TestCase
{
    private const WORKER_FLAGS = [
        'database' => 'REPRO_MYSQL_QUEUE_WORKER',
        'studio' => 'REPRO_MYSQL_STUDIO_QUEUE_WORKER',
        'iguide-lab-tour' => 'REPRO_MYSQL_IGUIDE_LAB_TOUR_QUEUE_WORKER',
    ];

    private Application $app;

    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('isolated-v1', getenv('MYSQL_QUEUE_WORKER_FIXTURE'));
        self::assertSame('testing', getenv('APP_ENV'));
        self::assertSame('mysql', getenv('DB_CONNECTION'));
        self::assertSame('repro_mysql_test', getenv('DB_DATABASE'));
        self::assertSame('repro_test', getenv('DB_USERNAME'));
        self::assertSame('/fixture-socket/mysql.sock', getenv('DB_SOCKET'));
        $this->admin = new PDO('mysql:unix_socket=/fixture-socket/mysql.sock;dbname=repro_mysql_test',
            'repro_token_fixture', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $identity = $this->admin->query('SELECT @@server_uuid, VERSION(), @@skip_networking, CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        self::assertSame([getenv('REPRO_MYSQL_EXPECTED_SERVER_UUID'), '8.4.13', 1, 'repro_token_fixture@localhost'], $identity);
        $tables = $this->admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='repro_mysql_test'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], array_diff($tables, ['jobs']));
        $this->admin->exec('DROP TABLE IF EXISTS jobs');
        $schema = file_get_contents(dirname(__DIR__, 3).'/database/schema/mysql-schema.sql');
        self::assertSame(1, preg_match('/^CREATE TABLE `jobs`\s+\([^;]+;/m', $schema, $jobsTable));
        $this->admin->exec($jobsTable[0]);

        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        self::assertSame('mysql_queue_worker', $this->app['config']->get('queue.connections.database.repro_worker_connection'));
        self::assertNull($this->app['config']->get('queue.connections.studio.repro_worker_connection'));
        self::assertNull($this->app['config']->get('queue.connections.iguide-lab-tour.repro_worker_connection'));
    }

    protected function tearDown(): void
    {
        if (isset($this->admin) && $this->admin->inTransaction()) {
            $this->admin->rollBack();
        }
        if (isset($this->app)) {
            foreach (['mysql', 'mysql_queue_worker', 'sqlite'] as $name) {
                $this->app['db']->disconnect($name);
            }
            $this->app->flush();
            HandleExceptions::flushState($this);
        }
        parent::tearDown();
    }

    private function queue(string $name = 'database'): MySqlWorkerDatabaseQueue
    {
        $queue = $this->app['queue']->connection($name);
        self::assertInstanceOf(MySqlWorkerDatabaseQueue::class, $queue);
        self::assertSame($this->app['db']->connection('mysql'), $queue->getDatabase());

        return $queue;
    }

    private function configuredQueues(string $engine, array $enabledQueues): array
    {
        $environment = $_ENV;
        $server = $_SERVER;
        $overrides = ['DB_CONNECTION' => $engine] + array_fill_keys(self::WORKER_FLAGS, 'false');
        foreach ($enabledQueues as $name) {
            $overrides[self::WORKER_FLAGS[$name]] = 'true';
        }
        try {
            $_ENV = $overrides + $_ENV;
            $_SERVER = $overrides + $_SERVER;
            $config = require dirname(__DIR__, 3).'/config/queue.php';

            return $config['connections'];
        } finally {
            $_ENV = $environment;
            $_SERVER = $server;
        }
    }

    private function jobRows(): int
    {
        return (int) $this->admin->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    }

    public function test_worker_claim_release_and_ack_use_the_same_scoped_session_while_token_is_held(): void
    {
        $queue = $this->queue();
        $sessions = [];
        $queue->getDatabase()->listen(function (QueryExecuted $event) use (&$sessions) {
            if (str_contains($event->sql, '`jobs`')) {
                $principal = $event->connection->getPdo()->query('SELECT CURRENT_USER(), CONNECTION_ID()')->fetch(PDO::FETCH_NUM);
                $sessions[] = [$event->connectionName, ...$principal];
            }
        });
        $id = $queue->push(new DispatchScheduledMessages, '', 'split-probe');
        self::assertCount(1, $sessions);
        self::assertSame(['mysql', 'repro_test@localhost'], array_slice($sessions[0], 0, 2));

        $this->admin->beginTransaction();
        $this->admin->query('SELECT id FROM repro_mysql_control.writer_token WHERE id = 1 FOR UPDATE')->closeCursor();
        $job = $queue->pop('split-probe');
        self::assertSame((string) $id, (string) $job?->getJobId());
        self::assertSame('database', $job->getConnectionName());
        self::assertSame(1, $job->attempts());
        $originalPayload = $job->getRawBody();
        $job->release(0);
        $retry = $queue->pop('split-probe');
        self::assertSame('database', $retry?->getConnectionName());
        self::assertSame(2, $retry->attempts());
        self::assertSame($originalPayload, $retry->getRawBody());
        $retry->delete();
        $this->admin->rollBack();

        self::assertGreaterThan(5, count($sessions));
        self::assertSame(['mysql_queue_worker', 'repro_queue_worker@localhost'], array_slice($sessions[1], 0, 2));
        self::assertNotSame($sessions[0][2], $sessions[1][2]);
        self::assertSame(array_fill(0, count($sessions) - 1, $sessions[1]), array_slice($sessions, 1));
        self::assertSame(0, $this->jobRows());
    }

    public function test_normal_raw_delayed_and_bulk_enqueues_and_clear_remain_transactional(): void
    {
        $queue = $this->queue();
        $business = $queue->getDatabase();
        $name = 'split-probe';
        $business->beginTransaction();
        try {
            $queue->push(new DispatchScheduledMessages, '', $name);
            $queue->pushRaw('{"probe":true}', $name);
            $queue->later(600, new DispatchScheduledMessages, '', $name);
            $queue->bulk([new DispatchScheduledMessages, new DispatchScheduledMessages], '', $name);
            self::assertSame(5, $business->table('jobs')->count());
            self::assertSame(0, $this->jobRows());
            self::assertNull($queue->pop($name));
            self::assertSame(1, $business->transactionLevel());
        } finally {
            $business->rollBack();
        }
        self::assertSame(0, $this->jobRows());
        $id = $queue->push(new DispatchScheduledMessages, '', $name);
        $business->beginTransaction();
        try {
            self::assertSame(1, $queue->clear($name));
            self::assertSame(1, $this->jobRows());
        } finally {
            $business->rollBack();
        }
        $job = $queue->pop($name);
        self::assertSame((string) $id, (string) $job?->getJobId());
        $queue->deleteReserved($name, $id);
        self::assertSame(0, $this->jobRows());
    }

    public function test_invalid_worker_identity_does_not_affect_enqueue_or_fall_back_when_claiming(): void
    {
        $this->app['config']->set('database.connections.mysql_queue_worker.repro_expected_server_uuid',
            '00000000-0000-0000-0000-000000000001');
        $queue = $this->queue();
        $queue->push(new DispatchScheduledMessages, '', 'split-probe');
        self::assertSame(1, $this->jobRows());
        try {
            $queue->pop('split-probe');
            self::fail('The wrong worker UUID must be refused.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('approved local database', $error->getMessage());
        }
        self::assertSame(0, (int) $this->admin->query('SELECT attempts FROM jobs')->fetchColumn());
    }

    public function test_worker_account_with_a_nonqueue_grant_is_refused(): void
    {
        $this->app['config']->set('database.connections.mysql_queue_worker.username', 'repro_queue_broad');
        $queue = $this->queue();
        $queue->push(new DispatchScheduledMessages, '', 'split-probe');
        try {
            $queue->pop('split-probe');
            self::fail('The broader worker account must be refused.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('only SELECT, INSERT, UPDATE and DELETE on jobs', $error->getMessage());
        }
        self::assertSame(0, (int) $this->admin->query('SELECT attempts FROM jobs')->fetchColumn());
    }

    public function test_the_claimed_job_checks_a_replacement_session_before_acknowledging(): void
    {
        $queue = $this->queue();
        $id = $queue->push(new DispatchScheduledMessages, '', 'split-probe');
        $job = $queue->pop('split-probe');
        self::assertSame((string) $id, (string) $job?->getJobId());

        $database = $this->app['db'];
        $pdo = $database->connection('mysql_queue_worker')->getPdo();
        $this->app['config']->set('database.connections.mysql_queue_worker.username', 'repro_queue_broad');
        $replacement = $database->reconnect('mysql_queue_worker')->getPdo();
        self::assertNotSame($pdo, $replacement);
        self::assertSame('repro_queue_broad@localhost', $replacement->query('SELECT CURRENT_USER()')->fetchColumn());
        try {
            $job->delete();
            self::fail('The job must refuse a broader replacement session.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('The MySQL queue worker', $error->getMessage());
        }
        self::assertSame(1, $this->jobRows());
        self::assertSame((string) $id, (string) $this->admin->query('SELECT id FROM jobs')->fetchColumn());

        $this->app['config']->set('database.connections.mysql_queue_worker.username', 'repro_queue_worker');
        $database->reconnect('mysql_queue_worker');
        $queue->deleteReserved('split-probe', $id);
        self::assertSame(0, $this->jobRows());
    }

    public function test_opt_out_preserves_the_stock_mysql_queue_and_the_sqlite_connector(): void
    {
        $this->app['config']->set('queue.connections', $this->configuredQueues('mysql', []));
        self::assertSame(DatabaseQueue::class, $this->app['queue']->connection('database')::class);
        $this->app['config']->set([
            'database.connections.sqlite.database' => ':memory:',
            'queue.connections.studio.connection' => 'sqlite',
        ]);
        self::assertSame(SqliteDatabaseQueue::class, $this->app['queue']->connection('studio')::class);
    }

    public function test_worker_route_flags_are_independent_and_mysql_only(): void
    {
        foreach (array_keys(self::WORKER_FLAGS) as $enabled) {
            $connections = $this->configuredQueues('mysql', [$enabled]);
            foreach (array_keys(self::WORKER_FLAGS) as $name) {
                if ($name === $enabled) {
                    self::assertSame('mysql_queue_worker', $connections[$name]['repro_worker_connection']);
                } else {
                    self::assertArrayNotHasKey('repro_worker_connection', $connections[$name]);
                }
            }
        }

        $sqlite = $this->configuredQueues('sqlite', array_keys(self::WORKER_FLAGS));
        foreach (array_keys(self::WORKER_FLAGS) as $name) {
            self::assertArrayNotHasKey('repro_worker_connection', $sqlite[$name]);
        }
    }

    public function test_alias_dispatch_defers_enqueue_until_commit_and_uses_the_business_session(): void
    {
        $this->app['config']->set('queue.connections', $this->configuredQueues('mysql', ['studio', 'iguide-lab-tour']));
        $studio = $this->queue('studio');
        $tour = $this->queue('iguide-lab-tour');
        $business = $studio->getDatabase();
        $inserts = [];
        $business->listen(function (QueryExecuted $event) use (&$inserts): void {
            if (str_starts_with($event->sql, 'insert into `jobs`')) {
                $inserts[] = $event->connectionName;
            }
        });

        $business->beginTransaction();
        try {
            $studio->push('queue-alias-probe');
            $tour->push('queue-alias-probe');
            self::assertSame(0, $business->table('jobs')->count());
            self::assertSame(0, $this->jobRows());
        } finally {
            $business->rollBack();
        }
        self::assertSame([], $inserts);
        self::assertSame(0, $this->jobRows());

        $business->transaction(function () use ($studio, $tour, $business): void {
            $studio->push('queue-alias-probe');
            $tour->push('queue-alias-probe');
            self::assertSame(0, $business->table('jobs')->count());
            self::assertSame(0, $this->jobRows());
        });
        self::assertSame(['mysql', 'mysql'], $inserts);
        self::assertSame(['studio', 'iguide-lab-tour'], $this->admin->query('SELECT queue FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(DatabaseQueue::class, $this->app['queue']->connection('database')::class);
    }

    public function test_aliases_share_the_verified_worker_and_keep_their_retry_windows(): void
    {
        $this->app['config']->set('queue.connections', $this->configuredQueues('mysql', ['studio', 'iguide-lab-tour']));
        $studio = $this->queue('studio');
        $tour = $this->queue('iguide-lab-tour');
        $studio->push('queue-alias-probe');
        $studio->push('queue-alias-probe');
        $tour->push('queue-alias-probe');
        $studioIds = $this->admin->query("SELECT id FROM jobs WHERE queue = 'studio' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $studioIds);
        [$youngStudio, $expiredStudio] = $studioIds;
        $expiredTour = $this->admin->query("SELECT id FROM jobs WHERE queue = 'iguide-lab-tour'")->fetchColumn();
        self::assertNotFalse($expiredTour);
        $reserve = $this->admin->prepare('UPDATE jobs SET reserved_at = ? WHERE id = ?');
        $now = time();
        $reserve->execute([$now - 1200, $youngStudio]);
        $reserve->execute([$now - 8000, $expiredStudio]);
        $reserve->execute([$now - 1200, $expiredTour]);

        $worker = $this->app['db']->connection('mysql_queue_worker');
        $sessions = [];
        $worker->listen(function (QueryExecuted $event) use (&$sessions): void {
            if (str_contains($event->sql, '`jobs`')) {
                $identity = $event->connection->getPdo()->query('SELECT CURRENT_USER(), CONNECTION_ID()')->fetch(PDO::FETCH_NUM);
                $sessions[] = [$event->connectionName, ...$identity];
            }
        });

        $this->admin->beginTransaction();
        try {
            $this->admin->query('SELECT id FROM repro_mysql_control.writer_token WHERE id = 1 FOR UPDATE')->closeCursor();
            $studioJob = $studio->pop();
            self::assertSame((string) $expiredStudio, (string) $studioJob?->getJobId());
            self::assertSame('studio', $studioJob->getConnectionName());
            self::assertSame('studio', $studioJob->getQueue());
            $studioJob->delete();
            self::assertNull($studio->pop());

            $tourJob = $tour->pop();
            self::assertSame((string) $expiredTour, (string) $tourJob?->getJobId());
            self::assertSame('iguide-lab-tour', $tourJob->getConnectionName());
            self::assertSame('iguide-lab-tour', $tourJob->getQueue());
            $tourJob->release(0);
            $retry = $tour->pop();
            self::assertNotNull($retry);
            self::assertSame('iguide-lab-tour', $retry->getConnectionName());
            self::assertSame(2, $retry->attempts());
            self::assertSame($tourJob->getRawBody(), $retry->getRawBody());

            self::assertGreaterThan(5, count($sessions));
            self::assertSame(['mysql_queue_worker', 'repro_queue_worker@localhost'], array_slice($sessions[0], 0, 2));
            self::assertSame(array_fill(0, count($sessions), $sessions[0]), $sessions);

            $previousSession = $worker->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
            $replacement = $this->app['db']->reconnect('mysql_queue_worker')->getPdo()
                ->query('SELECT CONNECTION_ID()')->fetchColumn();
            self::assertNotSame($previousSession, $replacement);
            $sessions = [];
            $retry->delete();
            self::assertNotEmpty($sessions);
            self::assertSame(array_fill(0, count($sessions),
                ['mysql_queue_worker', 'repro_queue_worker@localhost', $replacement]), $sessions);
        } finally {
            $this->admin->rollBack();
        }
        self::assertSame(1, $this->jobRows());
        self::assertSame((string) $youngStudio, (string) $this->admin->query('SELECT id FROM jobs')->fetchColumn());
    }
}
