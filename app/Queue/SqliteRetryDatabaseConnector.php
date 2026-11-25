<?php

namespace App\Queue;

use App\Database\MySqlConnection;
use Illuminate\Queue\Connectors\DatabaseConnector;

class SqliteRetryDatabaseConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        $database = $this->connections->connection($config['connection'] ?? null);
        if (($workerConnection = $config['repro_worker_connection'] ?? null) !== null) {
            return new MySqlWorkerDatabaseQueue($database, $config, fn () => $this->connections->connection($workerConnection));
        }

        if ($database instanceof MySqlConnection && $database->writerPolicyEnabled()) {
            return new MySqlDatabaseQueue(
                $database,
                $config['table'],
                $config['queue'],
                $config['retry_after'] ?? 60,
                $config['after_commit'] ?? null,
            );
        }

        if ($database->getDriverName() !== 'sqlite') {
            return parent::connect($config);
        }

        return new SqliteDatabaseQueue(
            $database,
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        );
    }
}
