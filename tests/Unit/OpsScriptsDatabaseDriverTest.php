<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The scripts in scripts/ops used to open /var/www/backend/database/database.sqlite directly.
 * After the move to MySQL that file is a stale copy, so they now use the app's configured
 * database connection, or refuse clearly when they can only work on SQLite.
 */
class OpsScriptsDatabaseDriverTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_failed_job_summary_and_clamav_prune_use_the_configured_connection(): void
    {
        $database = $this->failedJobsDatabase();
        $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database];

        [$status, $output, $error] = $this->runScript('summarize-failed-jobs.php', [], $env);
        $this->assertSame(0, $status, $error);
        $this->assertStringContainsString('total=3 ', $output);
        $this->assertStringContainsString("2\tClamAvUnavailable | ", $output);
        $this->assertStringContainsString("1\tRuntimeException | ", $output);

        [$status, $output, $error] = $this->runScript('prune-clamav-failed-jobs.php', [], $env);
        $this->assertSame(0, $status, $error);
        $this->assertSame("deleted_clamav=2 remaining=1\n", $output);
        $this->assertSame(1, (int) (new PDO('sqlite:'.$database))->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn());
    }

    public function test_service_55_correction_refuses_when_the_live_database_is_not_sqlite(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ops-engine-sqft-');
        $this->files[] = $database;
        (new PDO('sqlite:'.$database))->exec('CREATE TABLE service_sqft_ranges (id INTEGER PRIMARY KEY)');
        $before = hash_file('sha256', $database);

        [$status, $output, $error] = $this->runScript('correct-service-55-sqft-boundary.php', ['--database='.$database],
            ['DB_CONNECTION' => 'mysql', 'DB_SOCKET' => '/nonexistent/repro-ops-engine.sock']);

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('the live database is mysql', $error);
        $this->assertSame($before, hash_file('sha256', $database));
    }

    public function test_service_55_correction_refuses_when_db_url_points_the_sqlite_connection_at_mysql(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ops-engine-sqft-url-');
        $this->files[] = $database;
        (new PDO('sqlite:'.$database))->exec('CREATE TABLE service_sqft_ranges (id INTEGER PRIMARY KEY)');
        $before = hash_file('sha256', $database);

        [$status, $output, $error] = $this->runScript('correct-service-55-sqft-boundary.php', ['--database='.$database], [
            'DB_CONNECTION' => 'sqlite',
            'DB_URL' => 'mysql://repro@localhost/repro?unix_socket=/nonexistent/repro-ops-engine.sock',
        ]);

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('the live database is mysql', $error);
        $this->assertSame($before, hash_file('sha256', $database));
    }

    private function failedJobsDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ops-engine-failed-jobs-');
        $this->files[] = $path;
        $db = new PDO('sqlite:'.$path);
        $db->exec('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY, uuid TEXT, connection TEXT, queue TEXT, payload TEXT, exception TEXT, failed_at TEXT)');
        $insert = $db->prepare('INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES (?, ?, ?, ?, ?, ?)');
        $scan = json_encode(['displayName' => 'App\\Jobs\\ScanShootFileJob']);
        $insert->execute(['a', 'database', 'default', $scan, 'App\\Exceptions\\ClamAvUnavailable: down', '2026-11-01 10:00:00']);
        $insert->execute(['b', 'database', 'default', $scan, 'App\\Exceptions\\ClamAvUnavailable: down', '2026-11-02 10:00:00']);
        $insert->execute(['c', 'database', 'default', json_encode(['displayName' => 'App\\Jobs\\Other']), 'RuntimeException: other', '2026-11-03 10:00:00']);

        return $path;
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runScript(string $script, array $arguments, array $env): array
    {
        $path = dirname(__DIR__, 2).'/scripts/ops/'.$script;
        $process = proc_open([PHP_BINARY, $path, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), array_replace(getenv(), $env));
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
