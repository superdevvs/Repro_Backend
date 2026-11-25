<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds SQLite-only SQL and database engine branches in PHP source.
 *
 * Only string literals are checked for SQL, so PHP's own `||`, comments and HTTP headers such as
 * 'Pragma' do not count. A finding is keyed by file, rule and the trimmed source line, so moving
 * code up or down a file does not change the key.
 */
final class DatabaseDialectScanner
{
    private const BRANCH_RULE = 'engine-branch';

    /** PHP identifiers that read the database engine. */
    private const BRANCH_IDENTIFIERS = ['getDriverName', 'ATTR_DRIVER_NAME'];

    /** SQL that SQLite accepts but MySQL 8.4 rejects or answers differently. */
    private const SQL_RULES = [
        'json-each' => '/\bjson_(?:each|tree)\s*\(/i',
        'json-extract' => '/\bjson_extract\s*\(/i',
        'concat-pipes' => '/\|\|/',
        'sqlite-date-function' => '/\b(?:date|datetime|time)\s*\(\s*[\'"]now[\'"]|\b(?:strftime|julianday|unixepoch)\s*\(/i',
        'sqlite-catalog' => '/\bsqlite_(?:master|schema|sequence)\b|^\s*pragma\s+\w/i',
        'insert-or' => '/\binsert\s+or\s+(?:ignore|replace|abort|fail|rollback)\b/i',
        'sqlite-cast-type' => '/\bas\s+(?:integer|text|real|blob)\s*\)/i',
        'scalar-max-min' => '/\b(?:max|min)\s*\(\s*(?:[^(),\'"]|\([^()]*\))+,/i',
        'group-concat' => '/\bgroup_concat\s*\(/i',
        'iif' => '/\biif\s*\(/i',
        'typeof' => '/\btypeof\s*\(/i',
    ];

    /**
     * Rules whose pattern also appears outside SQL (PHP regexes, ffmpeg filters, inline
     * JavaScript). They count only in a string that looks like SQL.
     */
    private const SQL_CONTEXT_RULES = ['concat-pipes', 'scalar-max-min'];

    /** Calls whose string arguments are SQL. */
    private const SQL_CALLS = '/^(?:\w*Raw|raw|statement|unprepared|select|selectOne|scalar|cursor|insert|update|delete|affectingStatement|exec|query|prepare)$/i';

    /** Words that mark a string as SQL when it is not passed straight to an SQL call. */
    private const SQL_WORDS = '/\b(?:select|from|where|when|then|coalesce|nullif|cast|having|join|as)\b/i';

    public static function isBranchRule(string $rule): bool
    {
        return $rule === self::BRANCH_RULE;
    }

    /**
     * @return list<array{file:string,line:int,rule:string,text:string}>
     */
    public static function scanDirectory(string $root, string $relativeDirectory): array
    {
        $paths = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.'/'.$relativeDirectory, FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        $findings = [];
        foreach ($paths as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            array_push($findings, ...self::scanSource((string) file_get_contents($path), $relative));
        }

        return $findings;
    }

    /**
     * @return list<array{file:string,line:int,rule:string,text:string}>
     */
    public static function scanSource(string $source, string $file): array
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $hits = [];
        $branchLines = [];
        // Name of the function or method each open bracket belongs to (null for other brackets).
        $calls = [];
        $previous = null;

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                if ($token === '(') {
                    $calls[] = is_array($previous) && $previous[0] === T_STRING ? $previous[1] : null;
                } elseif ($token === ')') {
                    array_pop($calls);
                }
                $previous = $token;

                continue;
            }
            if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = $token;
            }
            [$id, $text, $line] = $token;

            if ($id === T_STRING && in_array($text, self::BRANCH_IDENTIFIERS, true)) {
                $branchLines[$line] = true;

                continue;
            }

            $literal = match ($id) {
                T_CONSTANT_ENCAPSED_STRING => substr($text, 1, -1),
                T_ENCAPSED_AND_WHITESPACE => $text,
                default => null,
            };
            if ($literal === null) {
                continue;
            }

            if (preg_match('/^sqlite(?:$|:|\s+format\s+3)/i', $literal)) {
                $branchLines[$line] = true;
            }
            $enclosingCall = $calls === [] ? null : end($calls);
            $looksLikeSql = ! str_starts_with(ltrim($literal), '<')
                && ((is_string($enclosingCall) && preg_match(self::SQL_CALLS, $enclosingCall))
                    || preg_match(self::SQL_WORDS, $literal));
            foreach (self::SQL_RULES as $rule => $pattern) {
                if ((! in_array($rule, self::SQL_CONTEXT_RULES, true) || $looksLikeSql) && preg_match($pattern, $literal)) {
                    $hits[] = [$line, $rule];
                }
            }
        }

        foreach (array_keys($branchLines) as $line) {
            $hits[] = [$line, self::BRANCH_RULE];
        }
        usort($hits, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $hit) => [
            'file' => $file,
            'line' => $hit[0],
            'rule' => $hit[1],
            'text' => mb_substr(preg_replace('/\s+/', ' ', trim($lines[$hit[0] - 1] ?? '')), 0, 300),
        ], $hits);
    }

    /**
     * @param  list<array{file:string,rule:string,text:string}>  $findings
     * @return list<array{file:string,rule:string,text:string,count:int}>
     */
    public static function group(array $findings): array
    {
        $counts = self::count($findings);

        return array_map(fn (string $key) => self::entry($key, $counts[$key]), array_keys($counts));
    }

    /**
     * Compares findings with allowed entries ({file, rule, text, count}).
     *
     * @return array{new: list<array>, stale: list<array>}
     */
    public static function compare(array $findings, array $allowed): array
    {
        $found = self::count($findings);
        $permitted = self::count($allowed);
        $new = [];
        $stale = [];

        foreach ($found as $key => $count) {
            if ($count > ($permitted[$key] ?? 0)) {
                $new[] = self::entry($key, $count - ($permitted[$key] ?? 0));
            }
        }
        foreach ($permitted as $key => $count) {
            if ($count > ($found[$key] ?? 0)) {
                $stale[] = self::entry($key, $count - ($found[$key] ?? 0));
            }
        }

        return ['new' => $new, 'stale' => $stale];
    }

    /**
     * @return array<string,int>
     */
    private static function count(array $entries): array
    {
        $counts = [];
        foreach ($entries as $entry) {
            $key = json_encode([$entry['file'], $entry['rule'], $entry['text']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $counts[$key] = ($counts[$key] ?? 0) + (int) ($entry['count'] ?? 1);
        }
        ksort($counts);

        return $counts;
    }

    private static function entry(string $key, int $count): array
    {
        [$file, $rule, $text] = json_decode($key, true);

        return ['file' => $file, 'rule' => $rule, 'text' => $text, 'count' => $count];
    }
}
