<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseDialectScanner;

/**
 * Production runs on SQLite today and moves to MySQL 8.4 later, so app/ must not gain new
 * SQLite-only SQL or new engine branches.
 *
 * - SQL that only SQLite understands (json_each, ||, DATE('now'), CAST AS INTEGER, two-argument
 *   MAX, PRAGMA, ...) is allowed only where tests/database-dialect-baseline.json lists it. The
 *   baseline may only shrink: remove an entry when you remove the SQL.
 * - Every engine branch (getDriverName(), PDO driver checks, DatabaseDriver::isSqlite(), 'sqlite'
 *   or 'mysql' names and DSNs) must be listed in tests/database-engine-branches.json, marked
 *   "safe" or "to-fix" with a reason.
 *
 * Regenerate the baseline after removing SQLite-only SQL. This only removes entries that are
 * gone; it never adds new SQL, so the guard still fails on it:
 *   DIALECT_GUARD_WRITE_BASELINE=1 php vendor/bin/phpunit tests/Unit/DatabaseDialectGuardTest.php
 */
class DatabaseDialectGuardTest extends TestCase
{
    private const BASELINE = 'tests/database-dialect-baseline.json';

    private const BRANCHES = 'tests/database-engine-branches.json';

    public function test_scanner_flags_sqlite_only_sql_and_engine_branches_but_not_php_code(): void
    {
        $source = <<<'PHP'
<?php
// json_each in a comment is not SQL.
if ($a || $b) { $driver = DB::connection()->getDriverName(); }
$q->whereRaw("EXISTS (SELECT 1 FROM json_each(media) AS m WHERE CAST(json_extract(m.value, '$.id') AS INTEGER) = ?)");
$q->selectRaw("first_name || ' ' || last_name AS full_name");
$q->whereRaw("DATE(due_date) < DATE('now')");
$q->selectRaw('MAX(COALESCE(balance_due, 0), total_amount) AS owed');
$pdo->exec('PRAGMA query_only=ON');
$pdo = new PDO('sqlite:'.$path);
$header = ['Pragma' => 'no-cache'];
$message = 'The backup failed its SQLite integrity check.';
DB::statement('INSERT OR IGNORE INTO tags (name) VALUES (?)', [$name]);
$q->selectRaw("first_name || last_name");
$filter = "zoompan=z='min(zoom+0.001,1.15)':d=150";
$clean = preg_replace('/^(?:!|\||-)+/u', '', $text);
$shim = '<script>(function(){ var a = b || c; })();</script>';
PHP;

        $rules = array_map(
            fn (array $finding) => $finding['line'].':'.$finding['rule'],
            DatabaseDialectScanner::scanSource($source, 'fixture.php'),
        );

        $this->assertEqualsCanonicalizing([
            '3:engine-branch',
            '4:json-each', '4:json-extract', '4:sqlite-cast-type',
            '5:concat-pipes',
            '6:sqlite-date-function',
            '7:scalar-max-min',
            '8:sqlite-catalog',
            '9:engine-branch',
            '12:insert-or',
            '13:concat-pipes',
        ], $rules);
    }

    public function test_scanner_flags_engine_checks_made_through_helpers_or_mysql_names(): void
    {
        // App\Support\DatabaseDriver::isSqlite() and comparisons with 'mysql' are engine branches
        // too; without this they would pass the guard unlisted.
        $source = <<<'PHP'
<?php
use App\Support\DatabaseDriver;
if (DatabaseDriver::isSqlite()) { $copy = true; }
if (config('database.default') === 'mysql') { $copy = false; }
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock');
$supported = in_array($driver, ['mariadb'], true);
$driver = DatabaseDriver::driver();
$socket = config('database.connections.mysql.unix_socket');
$message = 'MySQL 8.4 is the target.';
final class Probe { public static function isSqlite(): bool { return false; } }
PHP;

        $rules = array_map(
            fn (array $finding) => $finding['line'].':'.$finding['rule'],
            DatabaseDialectScanner::scanSource($source, 'fixture.php'),
        );

        $this->assertEqualsCanonicalizing([
            '3:engine-branch',
            '4:engine-branch',
            '5:engine-branch',
            '6:engine-branch',
        ], $rules);
    }

    public function test_compare_reports_new_and_stale_entries_with_counts(): void
    {
        $finding = ['file' => 'app/A.php', 'line' => 3, 'rule' => 'json-each', 'text' => 'x'];
        $allowed = [['file' => 'app/A.php', 'rule' => 'json-each', 'text' => 'x', 'count' => 1],
            ['file' => 'app/B.php', 'rule' => 'json-each', 'text' => 'y', 'count' => 1]];

        $result = DatabaseDialectScanner::compare([$finding, $finding], $allowed);

        $this->assertSame([['file' => 'app/A.php', 'rule' => 'json-each', 'text' => 'x', 'count' => 1]], $result['new']);
        $this->assertSame([['file' => 'app/B.php', 'rule' => 'json-each', 'text' => 'y', 'count' => 1]], $result['stale']);
    }

    public function test_rewriting_the_baseline_removes_stale_entries_but_never_adds_new_ones(): void
    {
        $finding = fn (string $file, string $text) => ['file' => $file, 'line' => 1, 'rule' => 'json-each', 'text' => $text];
        $allowed = [['file' => 'app/A.php', 'rule' => 'json-each', 'text' => 'x', 'count' => 2],
            ['file' => 'app/B.php', 'rule' => 'json-each', 'text' => 'y', 'count' => 1]];

        $baseline = DatabaseDialectScanner::shrink([$finding('app/A.php', 'x'), $finding('app/C.php', 'z')], $allowed);

        $this->assertSame([['file' => 'app/A.php', 'rule' => 'json-each', 'text' => 'x', 'count' => 1]], $baseline);
    }

    public function test_app_has_no_sqlite_only_sql_outside_the_baseline(): void
    {
        $findings = array_values(array_filter(
            DatabaseDialectScanner::scanDirectory($this->root(), 'app'),
            fn (array $finding) => ! DatabaseDialectScanner::isBranchRule($finding['rule']),
        ));

        if (getenv('DIALECT_GUARD_WRITE_BASELINE') === '1') {
            // Only removes entries that are gone. New SQLite-only SQL is never written in.
            $this->writeJson(self::BASELINE, [
                'about' => 'SQLite-only SQL that already exists in app/. This list may only shrink. See tests/Unit/DatabaseDialectGuardTest.php.',
                'findings' => DatabaseDialectScanner::shrink($findings, $this->readJson(self::BASELINE)['findings']),
            ]);
        }

        $result = DatabaseDialectScanner::compare($findings, $this->readJson(self::BASELINE)['findings']);

        $this->assertSame([], $result['new'], "New SQLite-only SQL in app/. Use a helper that works on SQLite and MySQL 8.4 (see AGENTS.md):\n"
            .json_encode($result['new'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame([], $result['stale'], 'These baseline entries no longer exist. Remove them from '.self::BASELINE.":\n"
            .json_encode($result['stale'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function test_every_engine_branch_is_listed_and_marked_safe_or_to_fix(): void
    {
        $findings = array_values(array_filter(
            DatabaseDialectScanner::scanDirectory($this->root(), 'app'),
            fn (array $finding) => DatabaseDialectScanner::isBranchRule($finding['rule']),
        ));
        $listed = $this->readJson(self::BRANCHES)['branches'];

        foreach ($listed as $entry) {
            $this->assertContains($entry['status'] ?? null, ['safe', 'to-fix'], json_encode($entry));
            $this->assertNotSame('', trim((string) ($entry['reason'] ?? '')), json_encode($entry));
        }

        $result = DatabaseDialectScanner::compare($findings, $listed);

        $this->assertSame([], $result['new'], 'New engine branch in app/. Prefer code that works on both engines; if a branch is needed, add it to '
            .self::BRANCHES." with status and reason:\n".json_encode($result['new'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame([], $result['stale'], 'These listed branches no longer exist. Remove them from '.self::BRANCHES.":\n"
            .json_encode($result['stale'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function test_repo_instructions_carry_the_database_rules(): void
    {
        foreach (['AGENTS.md', 'CLAUDE.md'] as $file) {
            $path = $this->root().'/'.$file;
            $this->assertFileExists($path);
            $text = (string) file_get_contents($path);

            $this->assertStringContainsString('Never change the isolation level to fix a race', $text, $file);
            $this->assertStringContainsString('tests/Unit/DatabaseDialectGuardTest.php', $text, $file);
            $this->assertStringContainsString('MySQL 8.4', $text, $file);
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function readJson(string $relative): array
    {
        $path = $this->root().'/'.$relative;
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeJson(string $relative, array $data): void
    {
        file_put_contents($this->root().'/'.$relative,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }
}
