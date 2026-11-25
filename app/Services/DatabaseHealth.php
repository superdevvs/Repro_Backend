<?php

namespace App\Services;

use App\Support\DatabaseEngine;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the database through the default connection and names its engine.
 *
 * Answers carry only fixed words, never an error message or a path, because
 * /up is public.
 */
final class DatabaseHealth
{
    /** @return array{status: 'ok'|'fail', engine: ?string, reason?: string, server_identity?: string} */
    public function check(): array
    {
        try {
            $connection = DB::connection();
            $engine = $connection->getDriverName();
        } catch (Throwable $e) {
            report($e);

            return $this->fail(null, 'database_unavailable');
        }

        if (! DatabaseEngine::matches($engine)) {
            return $this->fail($engine, 'engine_mismatch');
        }

        try {
            // A real table, so an empty or recreated database file fails too.
            $connection->table($this->migrationsTable())->exists();

            $expectedUuid = strtolower(trim((string) config('health.mysql_server_uuid', '')));
            if ($engine === 'mysql' && $expectedUuid !== '') {
                $actualUuid = strtolower((string) ($connection->selectOne('select @@server_uuid as uuid')->uuid ?? ''));

                return hash_equals($expectedUuid, $actualUuid)
                    ? ['status' => 'ok', 'engine' => $engine, 'server_identity' => 'match']
                    : $this->fail($engine, 'server_identity_mismatch');
            }
        } catch (Throwable $e) {
            report($e);

            return $this->fail($engine, 'database_unavailable');
        }

        return ['status' => 'ok', 'engine' => $engine];
    }

    private function fail(?string $engine, string $reason): array
    {
        return ['status' => 'fail', 'engine' => $engine, 'reason' => $reason];
    }

    private function migrationsTable(): string
    {
        $migrations = config('database.migrations', 'migrations');
        $table = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;

        return is_string($table) && $table !== '' ? $table : 'migrations';
    }
}
