<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connectors\ConnectionFactory;
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
    protected $signature = 'db:writer-policy-check';

    protected $description = 'Check the MySQL writer token, its session settings and connection layout (exit 1 on any problem)';

    public function handle(Repository $config, ConnectionFactory $factory): int
    {
        $default = (string) $config->get('database.default');
        $connections = (array) $config->get('database.connections');
        $engine = $connections[$default]['driver'] ?? null;
        if ($engine !== 'mysql') {
            return $this->report(['engine' => $engine, 'writer_policy' => 'not applicable', 'problems' => []]);
        }

        $problems = [];
        foreach ($connections as $name => $settings) {
            if ($name === $default || ($settings['driver'] ?? null) !== 'mysql') {
                continue;
            }
            if (($settings['repro_writer_policy'] ?? false) === true) {
                $problems[] = "Connection {$name} also carries the writer token; exactly one may.";
            } elseif (! is_array($settings['repro_writer_exempt_tables'] ?? null)) {
                $problems[] = "Connection {$name} writes MySQL without the writer token or a table limit.";
            }
        }
        $policy = ($connections[$default]['repro_writer_policy'] ?? false) === true;
        if (! $policy) {
            $problems[] = 'The writer policy is off on the default MySQL connection.';
        }

        $session = null;
        $acquired = false;
        $connection = null;
        try {
            // A fresh session, never the shared connection or an open transaction.
            $connection = $factory->make($connections[$default], $default.'-writer-policy-check');
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
