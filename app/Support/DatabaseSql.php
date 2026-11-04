<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/** Database portability helpers; SQL identifiers must be trusted by callers. */
final class DatabaseSql
{
    public static function concat(array $expressions, ?ConnectionInterface $connection = null): string
    {
        return ($connection ?? DB::connection())->getDriverName() === 'mysql'
            ? 'CONCAT('.implode(', ', $expressions).')'
            : '('.implode(' || ', $expressions).')';
    }

    public static function textCast(string $expression, ?ConnectionInterface $connection = null): string
    {
        $driver = ($connection ?? DB::connection())->getDriverName();

        return 'CAST('.$expression.' AS '.($driver === 'mysql' ? 'CHAR' : 'TEXT').')';
    }

    public static function jsonScalar(string $column, string $path, ?ConnectionInterface $connection = null): string
    {
        if (! preg_match('/\A\$\.[a-zA-Z0-9_]+\z/', $path)) {
            throw new \InvalidArgumentException('Only trusted scalar JSON paths are supported.');
        }

        $connection ??= DB::connection();
        $extract = "json_extract({$column}, '{$path}')";
        if ($connection->getDriverName() !== 'mysql') {
            return $extract;
        }

        $collation = $connection->getConfig('collation') ?: 'utf8mb4_bin';
        if (! preg_match('/\Autf8mb4_[a-zA-Z0-9_]+\z/', $collation)) {
            throw new \InvalidArgumentException('A supported utf8mb4 collation is required.');
        }

        // JSON null and the literal string "null" must remain distinct.
        return "(CASE WHEN JSON_TYPE({$extract}) COLLATE {$collation} = 'NULL' COLLATE {$collation} THEN NULL ELSE JSON_UNQUOTE({$extract}) END) COLLATE {$collation}";
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    public static function timestampString(mixed $value, ?ConnectionInterface $connection = null): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (! $value) {
            return null;
        }
        $text = (string) $value;
        if (($connection ?? DB::connection())->getDriverName() === 'mysql') {
            // DATETIME(6) pads whole seconds. Preserve the existing raw timestamp
            // API shape without discarding any nonzero fractional precision.
            $text = preg_replace('/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.0{6}\z/', '$1', $text) ?? $text;
        }

        return $text;
    }
}
