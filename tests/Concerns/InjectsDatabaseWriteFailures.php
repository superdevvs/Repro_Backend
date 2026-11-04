<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

trait InjectsDatabaseWriteFailures
{
    /** Inject a delete failure without DDL, which implicitly commits in MySQL. */
    protected function failDatabaseDeletesTo(string $table): \Closure
    {
        $state = (object) ['active' => true];
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use ($state, $table): void {
            if ($state->active && preg_match('/\Adelete\s+from\s+[`"]?'.preg_quote($table, '/').'[`"]?(?:\s|$)/i', $query)) {
                throw new QueryException($connection->getName(), $query, $bindings, new \PDOException('Injected database delete failure'));
            }
        });

        return static function () use ($state): void { $state->active = false; };
    }
}
