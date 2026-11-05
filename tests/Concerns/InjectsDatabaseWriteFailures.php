<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

trait InjectsDatabaseWriteFailures
{
    /** Inject a delete failure without DDL, which implicitly commits in MySQL. */
    protected function failDatabaseDeletesTo(string $table): \Closure
    {
        return $this->failDatabaseMutation('delete', $table);
    }

    protected function failDatabaseUpdatesTo(string $table): \Closure
    {
        return $this->failDatabaseMutation('update', $table);
    }

    private function failDatabaseMutation(string $verb, string $table): \Closure
    {
        $state = (object) ['active' => true];
        $prefix = $verb === 'delete' ? 'delete\\s+from' : 'update';
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use ($state, $table, $prefix, $verb): void {
            if ($state->active && preg_match('/\A'.$prefix.'\s+[`"]?'.preg_quote($table, '/').'[`"]?(?:\s|$)/i', $query)) {
                throw new QueryException($connection->getName(), $query, $bindings, new \PDOException('Injected database '.$verb.' failure'));
            }
        });

        return static function () use ($state): void { $state->active = false; };
    }
}
