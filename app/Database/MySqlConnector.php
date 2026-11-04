<?php

namespace App\Database;

/** Keep MySQL JSON scalar literals compatible with its built-in JSON collation. */
final class MySqlConnector extends \Illuminate\Database\Connectors\MySqlConnector
{
    public function connect(array $config)
    {
        $pdo = parent::connect($config);
        if (($config['charset'] ?? 'utf8mb4') === 'utf8mb4') {
            // Table/column collations retain NO PAD binary identifier semantics.
            // JSON_TYPE/JSON_UNQUOTE return utf8mb4_bin; matching the connection
            // literals prevents conflicts between the two binary collations.
            $pdo->exec("SET SESSION collation_connection = 'utf8mb4_bin'");
        }

        return $pdo;
    }
}
