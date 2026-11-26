<?php

namespace App\Database;

use PDO;
use RuntimeException;

/** Keep MySQL JSON scalar literals compatible with its built-in JSON collation. */
final class MySqlConnector extends \Illuminate\Database\Connectors\MySqlConnector
{
    public function connect(array $config)
    {
        $expectedUuid = $config['repro_expected_server_uuid'] ?? null;
        $schedulingCache = ($config['repro_scheduling_cache_only'] ?? false) === true;
        if (($config['repro_require_server_identity'] ?? false) || $expectedUuid !== null || $schedulingCache) {
            if (! is_string($expectedUuid)
                || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $expectedUuid)
                || ! str_starts_with((string) ($config['unix_socket'] ?? ''), '/')) {
                throw new RuntimeException('The approved MySQL UUID and an explicit local socket are required.');
            }
        }
        if ($schedulingCache && (! is_string($config['database'] ?? null)
            || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $config['database'])
            || ! is_string($config['username'] ?? null) || ! preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $config['username'])
            || ! is_string($config['password'] ?? null) || $config['password'] === ''
            || ($config['prefix'] ?? '') !== ''
            || ($config['options'][PDO::ATTR_PERSISTENT] ?? false))) {
            throw new RuntimeException('MySQL scheduling cache requires dedicated local credentials and a nonpersistent session.');
        }

        // SQLite reports matched rows for a no-op UPDATE. Heartbeats and guarded
        // writes rely on that contract, including repeated writes in one second.
        $config['options'] = [PDO::MYSQL_ATTR_FOUND_ROWS => true] + ($config['options'] ?? []);
        if (($config['repro_writer_policy'] ?? false) === true || is_array($config['repro_writer_exempt_tables'] ?? null)) {
            // One statement per call: the writer token and the table limit are decided
            // for the statement the classifier sees, so the server must not run a second one.
            $config['options'] = [PDO::MYSQL_ATTR_MULTI_STATEMENTS => false] + $config['options'];
        }
        $pdo = parent::connect($config);
        if ($expectedUuid !== null) {
            $target = $pdo->query('SELECT @@server_uuid AS uuid, VERSION() AS version, @@skip_networking AS local_only')
                ->fetch(PDO::FETCH_ASSOC);
            if (! $target || ! hash_equals(strtolower($expectedUuid), strtolower($target['uuid']))
                || ! preg_match('/\A8\.4\./', $target['version']) || (int) $target['local_only'] !== 1) {
                throw new RuntimeException('The configured MySQL connection did not reach the approved socket-only server.');
            }
        }
        if ($schedulingCache) {
            $this->verifySchedulingCache($pdo, $config);
        }
        if (isset($config['repro_lock_wait_timeout'])) {
            // Row and metadata lock waits end after this many seconds, as SQLite's busy wait does.
            // The writer token refuses a session with any other value.
            $seconds = (int) $config['repro_lock_wait_timeout'];
            $pdo->exec("SET SESSION innodb_lock_wait_timeout = {$seconds}, SESSION lock_wait_timeout = {$seconds}");
        }
        if (($config['charset'] ?? 'utf8mb4') === 'utf8mb4') {
            // Table/column collations retain NO PAD binary identifier semantics.
            // JSON_TYPE/JSON_UNQUOTE return utf8mb4_bin; matching the connection
            // literals prevents conflicts between the two binary collations.
            $pdo->exec("SET SESSION collation_connection = 'utf8mb4_bin'");
        }

        return $pdo;
    }

    private function verifySchedulingCache(PDO $pdo, array $config): void
    {
        $database = $config['database'];
        $username = $config['username'];
        $account = "`{$username}`@`localhost`";
        $identity = $pdo->query('SELECT DATABASE(), @@socket, CURRENT_USER(), CURRENT_ROLE()')->fetch(PDO::FETCH_NUM);
        $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        sort($grants);
        if ($identity !== [$database, $config['unix_socket'], $username.'@localhost', 'NONE'] || $grants !== [
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$database}`.`cache_locks` TO {$account}",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$database}`.`cache` TO {$account}",
            "GRANT USAGE ON *.* TO {$account}",
        ]) {
            throw new RuntimeException('MySQL scheduling cache requires only SELECT, INSERT, UPDATE and DELETE on cache and cache_locks.');
        }

        $tables = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('cache', 'cache_locks') AND TABLE_TYPE = 'BASE TABLE'")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        ksort($tables);
        if ($tables !== ['cache' => 'InnoDB', 'cache_locks' => 'InnoDB']) {
            throw new RuntimeException('MySQL scheduling cache requires the InnoDB cache and cache_locks tables.');
        }
    }
}
