<?php

namespace App\Support;

/**
 * The live database engine, read from config without opening a connection.
 *
 * Code that only works on SQLite (opening the SQLite file or a SQLite backup directly) checks
 * this first and refuses clearly on MySQL, instead of quietly reading a stale SQLite file.
 */
final class DatabaseDriver
{
    public static function driver(?string $connection = null): string
    {
        $name = $connection ?? (string) config('database.default');

        return (string) config("database.connections.{$name}.driver", '');
    }

    public static function isSqlite(?string $connection = null): bool
    {
        return self::driver($connection) === 'sqlite';
    }
}
