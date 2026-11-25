<?php

namespace App\Support;

use App\Database\DatabaseEngineMismatch;
use Illuminate\Database\Connection;

/** Runtime engine check: a process on the wrong database engine runs no query. */
final class DatabaseEngine
{
    /** The engine named by DB_EXPECTED_ENGINE, or null when the check is off. */
    public static function expected(): ?string
    {
        $expected = strtolower(trim((string) config('health.expected_engine', '')));

        return $expected === '' ? null : $expected;
    }

    public static function matches(string $driver): bool
    {
        $expected = self::expected();

        return $expected === null || $expected === strtolower($driver);
    }

    /**
     * Stop every statement on the default connection when its engine is wrong.
     * Other named connections are left alone: tools may read a second engine
     * on purpose.
     */
    public static function guard(Connection $connection): void
    {
        if ($connection->getName() !== config('database.default')) {
            return;
        }

        $driver = $connection->getDriverName();
        if (self::matches($driver)) {
            return;
        }

        $expected = (string) self::expected();
        $connection->beforeExecuting(static function () use ($connection, $driver, $expected): void {
            throw DatabaseEngineMismatch::for($connection->getName(), $driver, $expected);
        });
    }
}
