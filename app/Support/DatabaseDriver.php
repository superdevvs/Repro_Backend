<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;

/**
 * The live database engine, read from config without opening a connection.
 *
 * Code that only works on SQLite (opening the SQLite file or a SQLite backup directly) checks
 * this first and refuses clearly on MySQL, instead of quietly reading a stale SQLite file.
 *
 * The connection's `url` (DB_URL) is resolved the same way Laravel's DatabaseManager does, so a
 * mysql:// URL on a connection whose driver key still says sqlite counts as MySQL.
 */
final class DatabaseDriver
{
    public static function driver(?string $connection = null): string
    {
        $name = $connection ?? (string) config('database.default');
        $config = config("database.connections.{$name}");

        if (! is_array($config)) {
            return '';
        }

        return (string) ((new ConfigurationUrlParser)->parseConfiguration($config)['driver'] ?? '');
    }

    public static function isSqlite(?string $connection = null): bool
    {
        return self::driver($connection) === 'sqlite';
    }
}
