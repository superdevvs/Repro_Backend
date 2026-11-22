<?php

namespace App\Database;

/** Keep MySQL JSON scalar literals compatible with its built-in JSON collation. */
final class MySqlConnector extends \Illuminate\Database\Connectors\MySqlConnector
{
    public function connect(array $config)
    {
        $expectedUuid = $config['repro_expected_server_uuid'] ?? null;
        if (($config['repro_require_server_identity'] ?? false) || $expectedUuid !== null) {
            if (! is_string($expectedUuid)
                || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $expectedUuid)
                || ! str_starts_with((string) ($config['unix_socket'] ?? ''), '/')) {
                throw new \RuntimeException('The approved MySQL UUID and an explicit local socket are required.');
            }
        }

        // SQLite reports matched rows for a no-op UPDATE. Heartbeats and guarded
        // writes rely on that contract, including repeated writes in one second.
        $config['options'] = [\PDO::MYSQL_ATTR_FOUND_ROWS => true] + ($config['options'] ?? []);
        $pdo = parent::connect($config);
        if ($expectedUuid !== null) {
            $target = $pdo->query('SELECT @@server_uuid AS uuid, VERSION() AS version, @@skip_networking AS local_only')
                ->fetch(\PDO::FETCH_ASSOC);
            if (! $target || ! hash_equals(strtolower($expectedUuid), strtolower($target['uuid']))
                || ! preg_match('/\A8\.4\./', $target['version']) || (int) $target['local_only'] !== 1) {
                throw new \RuntimeException('The configured MySQL connection did not reach the approved socket-only server.');
            }
        }
        if (($config['charset'] ?? 'utf8mb4') === 'utf8mb4') {
            // Table/column collations retain NO PAD binary identifier semantics.
            // JSON_TYPE/JSON_UNQUOTE return utf8mb4_bin; matching the connection
            // literals prevents conflicts between the two binary collations.
            $pdo->exec("SET SESSION collation_connection = 'utf8mb4_bin'");
        }

        return $pdo;
    }
}
