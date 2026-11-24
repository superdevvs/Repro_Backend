<?php

namespace App\Queue;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use PDO;
use RuntimeException;
use WeakReference;

final class MySqlWorkerDatabaseQueue extends DatabaseQueue
{
    private ?DatabaseQueue $workerQueue = null;

    public function __construct(Connection $database, array $config, private readonly Closure $resolveWorker)
    {
        if ($database->getDriverName() !== 'mysql' || $config['table'] !== 'jobs' || $database->getTablePrefix() !== '') {
            throw new RuntimeException('The MySQL queue worker requires the unprefixed jobs table on MySQL.');
        }

        parent::__construct($database, $config['table'], $config['queue'], $config['retry_after'] ?? 60, $config['after_commit'] ?? null);
    }

    public function pop($queue = null)
    {
        return $this->workerQueue()->pop($queue);
    }

    public function deleteReserved($queue, $id)
    {
        $this->workerQueue()->deleteReserved($queue, $id);
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        $this->workerQueue()->deleteAndRelease($queue, $job, $delay);
    }

    private function workerQueue(): DatabaseQueue
    {
        if ($this->workerQueue === null) {
            $worker = ($this->resolveWorker)();
            $verified = WeakReference::create($this->verifyWorker($worker));
            $worker->beforeExecuting(function () use ($worker, &$verified): void {
                $worker->reconnectIfMissingConnection();
                if ($worker->getPdo() !== $verified->get()) {
                    // Laravel may reconnect when beginning a queue transaction.
                    // Verify its new PDO before that transaction touches a job.
                    $verified = WeakReference::create($this->verifyWorker($worker, reconnecting: true));
                }
            });

            // The stock job returned by pop() must retain this queue for release
            // and deletion. Enqueues continue to use the outer business queue.
            $this->workerQueue = new DatabaseQueue($worker, $this->table, $this->default, $this->retryAfter);
            $this->workerQueue->setContainer($this->container);
            $this->workerQueue->setConnectionName($this->connectionName);
        }

        return $this->workerQueue;
    }

    private function verifyWorker(Connection $worker, bool $reconnecting = false): PDO
    {
        $socket = $this->database->getConfig('unix_socket');
        $database = $this->database->getDatabaseName();
        $uuid = $this->database->getConfig('repro_expected_server_uuid');
        $username = $worker->getConfig('username');
        $password = $worker->getConfig('password');

        if ($worker === $this->database || $worker->getDriverName() !== 'mysql'
            || $worker->getTablePrefix() !== '' || $worker->getConfig('read') !== null || $worker->getConfig('write') !== null
            || ! is_string($socket) || ! str_starts_with($socket, '/') || $worker->getConfig('unix_socket') !== $socket
            || ! is_string($database) || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $database) || $worker->getDatabaseName() !== $database
            || ! is_string($uuid) || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $uuid)
            || $worker->getConfig('repro_expected_server_uuid') !== $uuid || $worker->getConfig('repro_require_server_identity') !== true
            || ! is_string($username) || ! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $username)
            || $username === $this->database->getConfig('username') || ! is_string($password) || $password === '') {
            throw new RuntimeException('The MySQL queue worker requires distinct jobs-only credentials for the approved local database.');
        }

        $businessPdo = $this->database->getPdo();
        $workerPdo = $worker->getPdo();
        if ($workerPdo === $businessPdo || (! $reconnecting && $workerPdo->inTransaction())
            || $workerPdo->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new RuntimeException('The MySQL queue worker requires a dedicated nonpersistent session outside a transaction.');
        }

        $identitySql = 'SELECT DATABASE(), LOWER(@@server_uuid), @@socket, @@skip_networking, CURRENT_USER()';
        $business = $businessPdo->query($identitySql)->fetch(PDO::FETCH_NUM);
        $actual = $workerPdo->query($identitySql)->fetch(PDO::FETCH_NUM);
        if ($business === false || $actual === false || $business[4] === $actual[4]
            || $actual !== [$database, strtolower($uuid), $socket, 1, $username.'@localhost']
            || array_slice($business, 0, 4) !== array_slice($actual, 0, 4)) {
            throw new RuntimeException('The MySQL queue worker and business sessions have different targets or the same principal.');
        }

        $account = "`{$username}`@`localhost`";
        $grants = $workerPdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        sort($grants);
        if ($grants !== [
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$database}`.`jobs` TO {$account}",
            "GRANT USAGE ON *.* TO {$account}",
        ] || $workerPdo->query('SELECT CURRENT_ROLE()')->fetchColumn() !== 'NONE') {
            throw new RuntimeException('The MySQL queue worker requires only SELECT, INSERT, UPDATE and DELETE on jobs.');
        }

        $engine = $workerPdo->query("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
        if ($engine !== 'InnoDB') {
            throw new RuntimeException('The MySQL queue worker requires an InnoDB jobs table.');
        }

        return $workerPdo;
    }
}
