<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SQLite3;
use Symfony\Component\Process\Process;

class DeploymentSqliteBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/repro-backup-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testBackupIncludesCommittedWalAndExcludesAnOpenWriteTransaction(): void
    {
        $source = new SQLite3($this->directory.'/source.sqlite');
        $source->exec('PRAGMA journal_mode=WAL');
        $source->exec('PRAGMA wal_autocheckpoint=0');
        $source->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $source->exec("INSERT INTO items VALUES (1, 'committed')");
        $source->exec('BEGIN IMMEDIATE');
        $source->exec("INSERT INTO items VALUES (2, 'uncommitted')");
        try {
            $this->assertGreaterThan(0, filesize($this->directory.'/source.sqlite-wal'));
            $process = $this->backup();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $backup = new SQLite3($this->directory.'/backup.sqlite', SQLITE3_OPEN_READONLY);
            $this->assertSame('ok', $backup->querySingle('PRAGMA quick_check'));
            $this->assertSame(1, $backup->querySingle('SELECT COUNT(*) FROM items'));
            $this->assertSame('committed', $backup->querySingle('SELECT value FROM items'));
            $backup->close();
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0600, fileperms($this->directory.'/backup.sqlite') & 0777);
            }
        } finally {
            $source->exec('ROLLBACK');
            $source->close();
        }
    }

    public function testExistingBackupIsNeverOverwritten(): void
    {
        file_put_contents($this->directory.'/source.sqlite', 'source placeholder');
        file_put_contents($this->directory.'/backup.sqlite', 'previous backup');
        $this->assertNotSame(0, $this->backup()->getExitCode());
        $this->assertSame('previous backup', file_get_contents($this->directory.'/backup.sqlite'));
    }

    public function testFailedBackupDoesNotLeaveAnIncompleteSnapshot(): void
    {
        file_put_contents($this->directory.'/source.sqlite', 'invalid sqlite contents');
        $this->assertNotSame(0, $this->backup()->getExitCode());
        $this->assertFileDoesNotExist($this->directory.'/backup.sqlite');
        $this->assertSame('invalid sqlite contents', file_get_contents($this->directory.'/source.sqlite'));
    }

    private function backup(): Process
    {
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 2).'/scripts/deploy/backup-sqlite.php',
            $this->directory.'/source.sqlite', $this->directory.'/backup.sqlite',
        ]);
        $process->setTimeout(15);
        $process->run();

        return $process;
    }
}
