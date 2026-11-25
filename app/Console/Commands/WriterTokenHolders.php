<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Watchdog for the MySQL writer token. Lists sessions that have held it longer
 * than a limit and exits 1 when there are any, so an alert can run it. Needs a
 * connection allowed to read performance_schema and INNODB_TRX (PROCESS).
 */
final class WriterTokenHolders extends Command
{
    protected $signature = 'db:writer-token-holders
        {--connection=mysql : MySQL connection that may read performance_schema and INNODB_TRX}
        {--older-than=5 : Report holders older than this many seconds}';

    protected $description = 'List sessions holding the MySQL writer token longer than a limit (exit 1 if any)';

    public function handle(DatabaseManager $db): int
    {
        $connection = $db->connection((string) $this->option('connection'));
        if ($connection->getDriverName() !== 'mysql') {
            $this->error('The writer token exists only on MySQL.');

            return 2;
        }
        $limit = max(0, (int) $this->option('older-than'));
        $rows = $connection->select("SELECT t.trx_mysql_thread_id AS thread_id,
                TIMESTAMPDIFF(SECOND, t.trx_started, NOW()) AS age_seconds,
                p.USER AS user, p.COMMAND AS command, p.TIME AS idle_seconds
            FROM performance_schema.data_locks l
            JOIN information_schema.INNODB_TRX t ON t.trx_id = l.ENGINE_TRANSACTION_ID
            LEFT JOIN performance_schema.processlist p ON p.ID = t.trx_mysql_thread_id
            WHERE l.OBJECT_SCHEMA = 'repro_mysql_control' AND l.OBJECT_NAME = 'writer_token'
                AND l.LOCK_TYPE = 'RECORD' AND l.LOCK_STATUS = 'GRANTED'
                AND TIMESTAMPDIFF(SECOND, t.trx_started, NOW()) >= ?
            ORDER BY t.trx_started", [$limit]);

        $holders = array_map(fn ($row) => [
            'thread_id' => (int) $row->thread_id,
            'age_seconds' => (int) $row->age_seconds,
            'user' => $row->user,
            'command' => $row->command,
            'idle_seconds' => $row->idle_seconds === null ? null : (int) $row->idle_seconds,
        ], $rows);
        $this->line(json_encode(['limit_seconds' => $limit, 'holders' => $holders], JSON_THROW_ON_ERROR));

        return $holders === [] ? self::SUCCESS : self::FAILURE;
    }
}
