<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

/**
 * Deploy check for the MySQL one-writer rule. On SQLite it reports that the
 * rule does not apply. On MySQL it fails unless exactly the default connection
 * carries the writer token, every other MySQL connection is limited to its
 * bookkeeping tables, a fresh session has READ COMMITTED and five-second lock
 * waits, and the token row can be taken and released.
 */
final class WriterPolicyCheck extends Command
{
    /**
     * The tables each token-free connection may write: queue and cache bookkeeping
     * only. Any other MySQL connection may skip the token for no table at all.
     */
    private const BOOKKEEPING_SCOPES = [
        'mysql_queue_worker' => ['jobs'],
        'mysql_scheduling_cache' => ['cache', 'cache_locks'],
        'mysql_cache_locks' => ['cache_locks'],
    ];

    protected $signature = 'db:writer-policy-check';

    protected $description = 'Check the MySQL writer token, its session settings and connection layout (exit 1 on any problem)';

    public function handle(Repository $config, ConnectionFactory $factory): int
    {
        $default = (string) $config->get('database.default');
        $connections = (array) $config->get('database.connections');
        // Resolve DB_URL for the default connection as Laravel's DatabaseManager does
        // before it connects, so the check inspects the database the application uses.
        $target = $connections[$default] ?? null;
        if (is_array($target) || is_string($target)) {
            $target = (new ConfigurationUrlParser)->parseConfiguration($target);
        }
        $engine = $target['driver'] ?? null;
        if ($engine !== 'mysql') {
            return $this->report(['engine' => $engine, 'writer_policy' => 'not applicable', 'problems' => []]);
        }

        $problems = [];
        foreach ($connections as $name => $settings) {
            // Other connections count by the driver they declare. The shipped sqlite, mariadb,
            // pgsql and sqlsrv templates read DB_URL too, but nothing connects through them.
            if ($name === $default || ! is_array($settings) || ($settings['driver'] ?? null) !== 'mysql') {
                continue;
            }
            $tables = $settings['repro_writer_exempt_tables'] ?? null;
            if (($settings['repro_writer_policy'] ?? false) === true) {
                $problems[] = "Connection {$name} also carries the writer token; exactly one may.";
            } elseif (! is_array($tables)) {
                $problems[] = "Connection {$name} writes MySQL without the writer token or a table limit.";
            } elseif (($outside = array_diff($tables, self::BOOKKEEPING_SCOPES[$name] ?? [])) !== []) {
                $problems[] = "Connection {$name} skips the writer token for tables outside its bookkeeping scope: "
                    .implode(', ', $outside).'.';
            }
        }
        $policy = ($target['repro_writer_policy'] ?? false) === true;
        if (! $policy) {
            $problems[] = 'The writer policy is off on the default MySQL connection.';
        }

        $session = null;
        $acquired = false;
        $connection = null;
        try {
            // A fresh session, never the shared connection or an open transaction.
            $connection = $factory->make($target, $default.'-writer-policy-check');
            $row = $connection->selectOne('SELECT @@SESSION.transaction_isolation AS isolation,
                @@SESSION.innodb_lock_wait_timeout AS row_lock_wait, @@SESSION.lock_wait_timeout AS metadata_lock_wait');
            $session = ['isolation' => $row->isolation, 'row_lock_wait' => (int) $row->row_lock_wait,
                'metadata_lock_wait' => (int) $row->metadata_lock_wait];
            if ($session !== ['isolation' => 'READ-COMMITTED', 'row_lock_wait' => 5, 'metadata_lock_wait' => 5]) {
                $problems[] = 'The MySQL session is not READ COMMITTED with five-second lock waits.';
            }
            if ($policy) {
                $connection->transaction(fn () => null);
                $acquired = true;
            }
        } catch (Throwable $error) {
            $problems[] = $error->getMessage();
        } finally {
            $connection?->disconnect();
        }

        return $this->report(['engine' => 'mysql', 'writer_policy' => $policy, 'session' => $session,
            'token' => ['acquired' => $acquired], 'problems' => $problems]);
    }

    private function report(array $report): int
    {
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['problems'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
