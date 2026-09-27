<?php

namespace App\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;

class SqliteRetryDatabaseConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        $database = $this->connections->connection($config['connection'] ?? null);
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
