<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Runs a callback with writes refused on the connection.
 *
 * On SQLite this sets PRAGMA query_only for this connection only, so any
 * INSERT, UPDATE, DELETE or DDL fails with a read-only error. Other engines
 * run the callback unchanged; callers must still only read.
 */
final class ReadOnlyDatabase
{
    public static function run(callable $callback, ?string $connection = null): mixed
    {
        $database = DB::connection($connection);
        if ($database->getDriverName() !== 'sqlite') {
            return $callback();
        }

        $previous = (int) $database->selectOne('PRAGMA query_only')->query_only;
        $database->statement('PRAGMA query_only = 1');
        try {
            return $callback();
        } finally {
            $database->statement('PRAGMA query_only = '.($previous === 1 ? 1 : 0));
        }
    }
}
