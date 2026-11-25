<?php

namespace App\Database;

/**
 * Classifies SQL for the MySQL one-writer rule.
 *
 * Writes at transaction level 0 run as BEGIN, token, statement, COMMIT. DDL,
 * session statements and reads are never wrapped. The scan ignores quoted text
 * and comments; anything it cannot place is OTHER and runs unchanged.
 */
final class WriterStatement
{
    public const WRITE = 'write';

    public const READ = 'read';

    public const LOCKING_READ = 'locking_read';

    public const DDL = 'ddl';

    public const TEMPORARY = 'temporary';

    public const SESSION = 'session';

    public const TRANSACTION_CONTROL = 'transaction_control';

    public const OTHER = 'other';

    private const WRITES = ['insert', 'update', 'delete', 'replace', 'call', 'load'];

    private const READS = ['select', 'show', 'explain', 'describe', 'desc', 'table', 'values'];

    private const DDL_WORDS = ['create', 'alter', 'drop', 'truncate', 'rename', 'grant', 'revoke'];

    private const SESSION_WORDS = ['set', 'use', 'do'];

    private const CONTROL_WORDS = ['begin', 'start', 'commit', 'rollback', 'savepoint', 'release', 'lock', 'unlock', 'xa'];

    /** @var array<string, string> */
    private static array $cache = [];

    public static function classify(string $sql): string
    {
        if (isset(self::$cache[$sql])) {
            return self::$cache[$sql];
        }
        $class = self::scan($sql);
        if (count(self::$cache) >= 2000) {
            self::$cache = [];
        }

        return self::$cache[$sql] = $class;
    }

    /** The one plain, unqualified table an INSERT, UPDATE, DELETE or REPLACE targets. */
    public static function singleTargetTable(string $sql): ?string
    {
        $words = self::topLevelWords($sql);
        $first = $words[0] ?? null;
        $pattern = match ($first) {
            'insert' => '/^\s*insert\s+(?:ignore\s+)?into\s+(`?)([A-Za-z0-9_]+)\1(?=[\s(]|$)/i',
            'replace' => '/^\s*replace\s+(?:into\s+)?(`?)([A-Za-z0-9_]+)\1(?=[\s(]|$)/i',
            'update' => '/^\s*update\s+(`?)([A-Za-z0-9_]+)\1\s+set\s/i',
            'delete' => '/^\s*delete\s+from\s+(`?)([A-Za-z0-9_]+)\1(?=\s|$)/i',
            default => null,
        };
        if ($pattern === null || ! preg_match($pattern, self::stripLeadingComments($sql), $match)) {
            return null;
        }
        // A join or second table in the statement makes the target ambiguous.
        if ($first === 'delete' && in_array('using', $words, true)) {
            return null;
        }

        return $match[2];
    }

    private static function scan(string $sql): string
    {
        $words = self::topLevelWords($sql);
        $first = $words[0] ?? null;
        if ($first === null) {
            return self::OTHER;
        }
        if ($first === 'with') {
            $first = null;
            foreach (array_slice($words, 1) as $word) {
                if (in_array($word, ['select', ...self::WRITES], true)) {
                    $first = $word;
                    break;
                }
            }
            if ($first === null) {
                return self::OTHER;
            }
        }

        if (in_array($first, self::WRITES, true)) {
            return self::WRITE;
        }
        if (in_array($first, self::READS, true)) {
            return self::isLocking($words) ? self::LOCKING_READ : self::READ;
        }
        if (in_array($first, self::DDL_WORDS, true)) {
            return in_array($first, ['create', 'drop'], true) && ($words[1] ?? null) === 'temporary'
                ? self::TEMPORARY : self::DDL;
        }
        if (in_array($first, self::SESSION_WORDS, true)) {
            return self::SESSION;
        }
        if (in_array($first, self::CONTROL_WORDS, true)) {
            return self::TRANSACTION_CONTROL;
        }

        return self::OTHER;
    }

    /** @param  list<string>  $words */
    private static function isLocking(array $words): bool
    {
        $count = count($words);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($words[$i] === 'for' && in_array($words[$i + 1], ['update', 'share'], true)) {
                return true;
            }
            if ($words[$i] === 'lock' && ($words[$i + 1] ?? null) === 'in' && ($words[$i + 2] ?? null) === 'share') {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-case bare words outside quotes and comments, at every parenthesis
     * depth for the first word and at depth 0 afterwards.
     *
     * @return list<string>
     */
    private static function topLevelWords(string $sql): array
    {
        $words = [];
        $depth = 0;
        $length = strlen($sql);
        $leading = true;
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if ($char === '-' && ($sql[$i + 1] ?? '') === '-' || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;

                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $i = self::skipQuoted($sql, $i, $char);
                $leading = false;

                continue;
            }
            if ($char === '(') {
                $depth++;
                $i++;

                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $i++;
                $leading = false;

                continue;
            }
            if (ctype_alpha($char) || $char === '_') {
                $start = $i;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$')) {
                    $i++;
                }
                // A leading "(select ...)" union belongs to the statement's first word.
                if ($depth === 0 || ($leading && $words === [])) {
                    $words[] = strtolower(substr($sql, $start, $i - $start));
                }
                $leading = false;

                continue;
            }
            $i++;
        }

        return $words;
    }

    private static function skipQuoted(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] === '\\' && $quote !== '`') {
                $i++;

                continue;
            }
            if ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++;

                    continue;
                }

                return $i + 1;
            }
        }

        return $length;
    }

    private static function stripLeadingComments(string $sql): string
    {
        return (string) preg_replace('~\A(?:\s+|--[^\n]*(?:\n|\z)|#[^\n]*(?:\n|\z)|/\*.*?\*/)*~s', '', $sql);
    }
}
