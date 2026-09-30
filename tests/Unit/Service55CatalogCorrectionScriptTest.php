<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Service55CatalogCorrectionScriptTest extends TestCase
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

    private function fixture(array $overrides = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'service-55-test-');
        $this->files[] = $path;
        $db = new PDO('sqlite:'.$path);
        $db->exec('CREATE TABLE service_sqft_ranges (id INTEGER PRIMARY KEY, service_id INTEGER, sqft_from INTEGER, sqft_to INTEGER, price NUMERIC, photographer_pay NUMERIC)');
        $row = array_replace([154, 55, 1500, 3000, 575, 123], $overrides);
        $db->prepare('INSERT INTO service_sqft_ranges VALUES (?, ?, ?, ?, ?, ?)')->execute($row);

        return $path;
    }

    private function runScript(string $path, array $arguments = []): array
    {
        $script = dirname(__DIR__, 2).'/scripts/ops/correct-service-55-sqft-boundary.php';
        $process = proc_open([PHP_BINARY, $script, '--database='.$path, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }

    public function test_default_dry_run_leaves_database_bytes_unchanged(): void
    {
        $path = $this->fixture();
        $before = hash_file('sha256', $path);
        [$status, $output] = $this->runScript($path);
        $this->assertSame(0, $status);
        $this->assertSame('would-correct', json_decode($output, true)['status']);
        $this->assertSame($before, hash_file('sha256', $path));
    }

    public function test_apply_requires_confirmation_and_backup_then_changes_only_the_boundary(): void
    {
        $path = $this->fixture();
        $this->assertSame(1, $this->runScript($path, ['--apply'])[0]);
        $confirmation = '--confirm=service-55-range-154-1500-to-1501';
        $this->assertSame(1, $this->runScript($path, ['--apply', $confirmation])[0]);
        $backup = tempnam(sys_get_temp_dir(), 'service-55-backup-test-');
        $this->files[] = $backup;
        copy($path, $backup);
        $arguments = ['--apply', $confirmation, '--backup='.$backup];
        [$status, $output] = $this->runScript($path, $arguments);
        $this->assertSame(0, $status);
        $this->assertSame('corrected', json_decode($output, true)['status']);
        $row = (new PDO('sqlite:'.$path))->query('SELECT * FROM service_sqft_ranges')->fetch(PDO::FETCH_NUM);
        $this->assertEquals([154, 55, 1501, 3000, 575, 123], $row);
        $this->assertSame(1500, (int) (new PDO('sqlite:'.$backup))->query('SELECT sqft_from FROM service_sqft_ranges')->fetchColumn());
        $this->assertSame('already-corrected', json_decode($this->runScript($path, $arguments)[1], true)['status']);
    }

    public static function changedRows(): array
    {
        return [[[0 => 155]], [[1 => 56]], [[2 => 1499]], [[3 => 3001]], [[4 => 574]]];
    }

    #[DataProvider('changedRows')]
    public function test_drift_refuses_without_mutating(array $overrides): void
    {
        $path = $this->fixture($overrides);
        $before = hash_file('sha256', $path);
        $this->assertSame(1, $this->runScript($path)[0]);
        $this->assertSame($before, hash_file('sha256', $path));
    }
}
